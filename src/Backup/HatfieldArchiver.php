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
 */
final readonly class HatfieldArchiver
{
    private const string HATFIELD_DIR = '.hatfield';
    private const string STATE_DATABASE = '.hatfield/state.sqlite';
    private const string WAL_SUFFIX = '-wal';
    private const string QUICK_CHECK_OK = 'ok';
    private const int SNAPSHOT_ATTEMPTS = 3;

    /** GNU tar exclude patterns (unanchored, wildcards match '/'). */
    private const array EXCLUDES = [
        '.hatfield/cache',
        '.hatfield/logs',
        '.hatfield/tmp',
        '.hatfield/locks',
        '.hatfield/extensions',
        '.hatfield/extensions-data/observational-memory',
        '.hatfield/state.sqlite*',
        '.hatfield/sessions/*/runtime',
        '.hatfield/sessions/*/messenger-queue.sqlite*',
        '.hatfield/sessions/*/history-index.sqlite*',
        '*.lock',
    ];

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return bool false when the project has no `.hatfield` directory
     */
    public function archive(string $projectRoot, string $target, string $workDir): bool
    {
        $root = rtrim($projectRoot, '/');
        if (!is_dir($root.'/'.self::HATFIELD_DIR)) {
            return false;
        }

        $tar = preg_replace('/\.gz$/', '', $target) ?? $target;
        $snapshotRoot = $workDir.'/'.bin2hex(random_bytes(8));
        try {
            $command = ['tar', '-cf', $tar, '-C', $root, '--warning=no-file-changed', '--warning=no-file-removed'];
            foreach (self::EXCLUDES as $pattern) {
                $command[] = '--exclude='.$pattern;
            }
            $command[] = self::HATFIELD_DIR;
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
