<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Archives a project's `.hatfield` directory without disposable or runtime files.
 *
 * `state.sqlite` is a WAL database that SQLite cannot open from a read-only mount (it must create
 * `-shm`). Its main file and `-wal` are copied together into the work directory, retried while
 * they change under the copy; SQLite recovers the WAL on the private copy, the copy must pass
 * `quick_check`, and an online backup of it is appended to the archive at its original path.
 * A live Hatfield session can still race the copy; stopping Hatfield gives an exact snapshot.
 * Source files are only read.
 *
 * A custom `sessions.path` (resolved from current settings at backup time) inside the project root
 * is archived too, at its path relative to the root, with the same per-session exclusions.
 * A sessions directory outside the project root has no restorable place in the archive and
 * fails the backup instead of silently omitting the session history.
 */
final readonly class HatfieldArchiver
{
    private const string HATFIELD_DIR = '.hatfield';
    private const string STATE_DATABASE = '.hatfield/state.sqlite';
    private const string WAL_SUFFIX = '-wal';
    private const string QUICK_CHECK_OK = 'ok';
    private const int SNAPSHOT_ATTEMPTS = 3;

    private const string DEFAULT_SESSIONS_DIR = '.hatfield/sessions';

    /** Per-session disposable entries, relative to a sessions directory. */
    private const array SESSION_EXCLUDES = ['*/runtime', '*/messenger-queue.sqlite*', '*/history-index.sqlite*'];

    /** GNU tar exclude patterns (unanchored, wildcards match '/'). */
    private const array EXCLUDES = [
        '.hatfield/cache',
        '.hatfield/logs',
        '.hatfield/tmp',
        '.hatfield/locks',
        '.hatfield/extensions',
        '.hatfield/extensions-data/observational-memory',
        '.hatfield/state.sqlite*',
        '*.lock',
    ];

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param string|null $sessionsPath effective sessions directory; null uses the default
     *
     * @return bool false when the project has neither `.hatfield` nor a custom sessions directory
     */
    public function archive(string $projectRoot, ?string $sessionsPath, string $target, string $workDir): bool
    {
        $root = rtrim($projectRoot, '/');
        $members = is_dir($root.'/'.self::HATFIELD_DIR) ? [self::HATFIELD_DIR] : [];
        $sessionDirs = [self::DEFAULT_SESSIONS_DIR];

        $customSessions = $this->customSessionsDirectory($root, $sessionsPath);
        if (null !== $customSessions) {
            $sessionDirs[] = $customSessions;
            if (!str_starts_with($customSessions.'/', self::HATFIELD_DIR.'/')) {
                $members[] = $customSessions;
            }
        }
        if ([] === $members) {
            return false;
        }

        $tar = preg_replace('/\.gz$/', '', $target) ?? $target;
        $snapshotRoot = $workDir.'/'.bin2hex(random_bytes(8));
        try {
            $command = ['tar', '-cf', $tar, '-C', $root, '--warning=no-file-changed', '--warning=no-file-removed'];
            foreach (self::EXCLUDES as $pattern) {
                $command[] = '--exclude='.$pattern;
            }
            foreach ($sessionDirs as $sessionDir) {
                foreach (self::SESSION_EXCLUDES as $pattern) {
                    $command[] = '--exclude='.$sessionDir.'/'.$pattern;
                }
            }
            $command[] = '--';
            array_push($command, ...$members);
            ArchiveCommand::run($command, acceptedExitCodes: [0, ArchiveCommand::TAR_FILES_CHANGED]);

            if (is_file($root.'/'.self::STATE_DATABASE)) {
                $this->snapshotState($root.'/'.self::STATE_DATABASE, $snapshotRoot.'/'.self::STATE_DATABASE);
                ArchiveCommand::run(['tar', '-rf', $tar, '-C', $snapshotRoot, self::STATE_DATABASE]);
            }

            ArchiveCommand::run(['gzip', '-f', $tar]);
        } finally {
            $this->filesystem->remove([$snapshotRoot, $tar]);
        }

        return true;
    }

    /**
     * @return string|null the custom sessions directory relative to the root; null for the default
     */
    private function customSessionsDirectory(string $root, ?string $sessionsPath): ?string
    {
        if (null === $sessionsPath || '' === $sessionsPath) {
            return null;
        }
        $sessions = rtrim($sessionsPath, '/');
        if (!str_starts_with($sessions.'/', $root.'/') || $sessions === $root) {
            throw new \RuntimeException(\sprintf('Sessions directory %s is outside the project root %s; backup does not support it.', $sessions, $root));
        }
        $relative = substr($sessions, \strlen($root) + 1);
        if (self::DEFAULT_SESSIONS_DIR === $relative) {
            return null; // archived with .hatfield; a missing default directory means no sessions yet
        }
        // An explicitly configured location must exist: a vanished directory or unavailable mount
        // would otherwise yield a "successful" backup without session history.
        if (!is_dir($sessions) || !is_readable($sessions)) {
            throw new \RuntimeException(\sprintf('Configured sessions directory %s is missing or unreadable.', $sessions));
        }

        return $relative;
    }

    private function snapshotState(string $source, string $target): void
    {
        $this->filesystem->mkdir(\dirname($target));
        $copy = \dirname($target).'/copy.sqlite';
        for ($attempt = 1; $attempt <= self::SNAPSHOT_ATTEMPTS; ++$attempt) {
            $this->filesystem->remove([$copy, $copy.self::WAL_SUFFIX]);
            $before = $this->fingerprint($source);
            $this->filesystem->copy($source, $copy, true);
            if (is_file($source.self::WAL_SUFFIX)) {
                $this->filesystem->copy($source.self::WAL_SUFFIX, $copy.self::WAL_SUFFIX, true);
            }
            if ($before === $this->fingerprint($source)) {
                break;
            }
            if (self::SNAPSHOT_ATTEMPTS === $attempt) {
                throw new \RuntimeException('state.sqlite kept changing while being copied.');
            }
        }

        $from = new \SQLite3($copy);
        $to = new \SQLite3($target);
        try {
            $from->enableExceptions(true);
            if (self::QUICK_CHECK_OK !== $from->querySingle('PRAGMA quick_check')) {
                throw new \RuntimeException('Copied state.sqlite failed quick_check.');
            }
            if (!$from->backup($to)) {
                throw new \RuntimeException('SQLite online backup of state.sqlite failed.');
            }
        } finally {
            $from->close();
            $to->close();
            $this->filesystem->remove([$copy, $copy.self::WAL_SUFFIX]);
        }
    }

    /** @return list<int|false> size and mtime of the database and its WAL */
    private function fingerprint(string $database): array
    {
        clearstatcache();
        $wal = $database.self::WAL_SUFFIX;

        return [@filesize($database), @filemtime($database), @filesize($wal), @filemtime($wal)];
    }
}
