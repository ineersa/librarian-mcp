<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Test stand-in for GCS: objects are files under a local directory. Wired only in the test environment.
 */
final readonly class LocalDirectoryBackupStorage implements BackupStorage
{
    private const string URI_SCHEME = 'file://';

    public function __construct(
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function upload(string $localPath, string $objectName): void
    {
        $this->filesystem->copy($localPath, $this->path($objectName), true);
    }

    public function listObjects(string $prefix): array
    {
        $directory = \dirname($this->path($prefix.'x'));
        if (!is_dir($directory)) {
            return [];
        }

        $objects = [];
        $base = rtrim($this->directory, '/').'/';
        $files = glob($directory.'/*');
        foreach (false === $files ? [] : $files as $file) {
            $name = substr($file, \strlen($base));
            if (is_file($file) && str_starts_with($name, $prefix)) {
                $objects[] = new BackupObject($name, new \DateTimeImmutable('@'.filemtime($file)));
            }
        }
        // Same-second uploads keep their (timestamped) name order.
        usort($objects, static fn (BackupObject $a, BackupObject $b): int => [$a->createdAt, $a->name] <=> [$b->createdAt, $b->name]);

        return $objects;
    }

    public function delete(string $objectName): void
    {
        $this->filesystem->remove($this->path($objectName));
    }

    public function uri(string $objectName): string
    {
        return self::URI_SCHEME.$this->path($objectName);
    }

    private function path(string $objectName): string
    {
        return rtrim($this->directory, '/').'/'.$objectName;
    }
}
