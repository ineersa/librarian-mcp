<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Paths and hashing helpers for plan 03/04 synthetic project fixtures.
 *
 * Builder target: /app/var/browser-live/project-sources
 * Application mount: /project-sources
 *
 * Fixture mutation is allowed only through these helpers against harness-owned
 * synthetic paths. Never mutate owner/source trees from the application.
 */
final class ProjectSourceFixture
{
    public const string BUILDER_ROOT = '/app/var/browser-live/project-sources';
    public const string VISIBLE_ROOT = '/project-sources';
    public const string PROJECTS_DIR = 'projects';
    public const string OWNERSHIP_MARKER_BASENAME = 'SYNTHETIC_PROJECT_SOURCES';
    public const string OWNERSHIP_MARKER = "librarian-plan03-synthetic-project-sources\n";

    public static function builderProjectPath(string $slug): string
    {
        return self::BUILDER_ROOT.'/'.self::PROJECTS_DIR.'/'.$slug;
    }

    public static function visibleProjectPath(string $slug): string
    {
        return self::VISIBLE_ROOT.'/'.self::PROJECTS_DIR.'/'.$slug;
    }

    public static function defaultOmDatabasePath(string $slug = 'default-om'): string
    {
        return self::builderProjectPath($slug).'/.hatfield/extensions-data/observational-memory/om.sqlite';
    }

    /**
     * @return array<string, string> relativePath => sha256
     */
    public static function hashTree(string $absoluteRoot): array
    {
        if (!is_dir($absoluteRoot)) {
            throw new \InvalidArgumentException(\sprintf('Fixture root missing: %s', $absoluteRoot));
        }

        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $path = $fileInfo->getPathname();
            $relative = substr($path, \strlen($absoluteRoot) + 1);
            if ($fileInfo->isLink()) {
                $hashes[$relative] = 'symlink:'.(string) readlink($path);

                continue;
            }
            if ($fileInfo->isDir()) {
                $hashes[$relative.'/'] = 'dir';

                continue;
            }
            if (!$fileInfo->isReadable()) {
                $hashes[$relative] = 'unreadable:'.\sprintf('%04o', $fileInfo->getPerms() & 0777);

                continue;
            }
            $hashes[$relative] = hash_file('sha256', $path) ?: throw new \RuntimeException('Unable to hash '.$path);
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     */
    public static function assertUnchanged(array $before, array $after, string $label): void
    {
        if ($before !== $after) {
            throw new \RuntimeException(\sprintf("%s source tree changed.\nBefore keys: %s\nAfter keys: %s", $label, implode(',', array_keys($before)), implode(',', array_keys($after))));
        }
    }

    /**
     * Updates one observation content and bumps om_source_revision on a verified
     * harness-owned synthetic OM database. Restores previous values via the
     * returned callable so repeated browser runs stay idempotent.
     *
     * @return callable(): void
     */
    public static function updateObservationContent(
        string $builderProjectRoot,
        string $databaseRelativePath,
        string $observationId,
        string $newContent,
        int $newRevision,
    ): callable {
        self::assertOwnedFixtureRoot($builderProjectRoot);

        $databasePath = $builderProjectRoot.'/'.ltrim($databaseRelativePath, '/');
        if (!is_file($databasePath) || is_link($databasePath)) {
            throw new \RuntimeException(\sprintf('Owned fixture OM database missing or symlinked: %s', $databasePath));
        }

        $pdo = new \PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT content, content_hash FROM om_observation WHERE observation_id = :id');
            $select->execute(['id' => $observationId]);
            $row = $select->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($row) || !isset($row['content'], $row['content_hash'])) {
                throw new \RuntimeException(\sprintf('Observation %s not found in owned fixture database.', $observationId));
            }

            $previousContent = (string) $row['content'];
            $previousHash = (string) $row['content_hash'];
            $revisionRow = $pdo->query('SELECT revision FROM om_source_revision WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($revisionRow) || !isset($revisionRow['revision'])) {
                throw new \RuntimeException('Owned fixture OM database is missing om_source_revision.');
            }
            $previousRevision = (int) $revisionRow['revision'];

            $update = $pdo->prepare('UPDATE om_observation SET content = :content, content_hash = :hash WHERE observation_id = :id');
            $update->execute([
                'content' => $newContent,
                'hash' => hash('sha256', $newContent),
                'id' => $observationId,
            ]);
            $pdo->prepare('UPDATE om_source_revision SET revision = :revision WHERE id = 1')
                ->execute(['revision' => $newRevision]);
            $pdo->prepare('INSERT INTO om_source_change (kind, source_id, revision) VALUES (?, ?, ?)
                ON CONFLICT(kind, source_id) DO UPDATE SET revision = excluded.revision')
                ->execute(['observation', $observationId, $newRevision]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        return static function () use ($databasePath, $observationId, $previousContent, $previousHash, $previousRevision): void {
            $pdo = new \PDO('sqlite:'.$databasePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE om_observation SET content = :content, content_hash = :hash WHERE observation_id = :id')
                    ->execute([
                        'content' => $previousContent,
                        'hash' => $previousHash,
                        'id' => $observationId,
                    ]);
                $pdo->prepare('UPDATE om_source_revision SET revision = :revision WHERE id = 1')
                    ->execute(['revision' => $previousRevision]);
                $pdo->prepare('INSERT INTO om_source_change (kind, source_id, revision) VALUES (?, ?, ?)
                    ON CONFLICT(kind, source_id) DO UPDATE SET revision = excluded.revision')
                    ->execute(['observation', $observationId, $previousRevision]);
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }
        };
    }

    private static function assertOwnedFixtureRoot(string $builderProjectRoot): void
    {
        $realRoot = realpath(self::BUILDER_ROOT);
        $realProject = realpath($builderProjectRoot);
        if (false === $realRoot || false === $realProject) {
            throw new \RuntimeException('Unable to resolve owned synthetic fixture paths.');
        }
        if (!str_starts_with($realProject, $realRoot.'/'.self::PROJECTS_DIR.'/')) {
            throw new \RuntimeException(\sprintf('Refusing fixture mutation outside owned projects root: %s', $builderProjectRoot));
        }

        $marker = self::BUILDER_ROOT.'/'.self::OWNERSHIP_MARKER_BASENAME;
        if (!is_file($marker) || self::OWNERSHIP_MARKER !== (string) file_get_contents($marker)) {
            throw new \RuntimeException('Synthetic ownership marker missing or foreign; refusing fixture mutation.');
        }
    }
}
