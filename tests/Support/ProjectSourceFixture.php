<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Paths and hashing helpers for plan 03 synthetic project fixtures.
 *
 * Builder target: /app/var/browser-live/project-sources
 * Application mount: /project-sources
 */
final class ProjectSourceFixture
{
    public const string BUILDER_ROOT = '/app/var/browser-live/project-sources';
    public const string VISIBLE_ROOT = '/project-sources';
    public const string PROJECTS_DIR = 'projects';

    public static function builderProjectPath(string $slug): string
    {
        return self::BUILDER_ROOT.'/'.self::PROJECTS_DIR.'/'.$slug;
    }

    public static function visibleProjectPath(string $slug): string
    {
        return self::VISIBLE_ROOT.'/'.self::PROJECTS_DIR.'/'.$slug;
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
}
