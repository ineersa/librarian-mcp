<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Remote backup destination. Production uses Google Cloud Storage; tests use a local directory.
 */
interface BackupStorage
{
    public function upload(string $localPath, string $objectName): void;

    /**
     * @return list<BackupObject> objects under the prefix, oldest first
     */
    public function listObjects(string $prefix): array;

    public function delete(string $objectName): void;

    /** Human-readable location, e.g. gs://bucket/object. */
    public function uri(string $objectName): string;
}
