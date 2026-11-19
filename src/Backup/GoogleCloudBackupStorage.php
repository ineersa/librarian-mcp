<?php

declare(strict_types=1);

namespace App\Backup;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ported from the owner's cron-jobs GoogleCloudStorageService: upload, list sorted by
 * `timeCreated`, delete. The client is created on first use so an unconfigured
 * installation only fails when a backup actually runs.
 */
final class GoogleCloudBackupStorage implements BackupStorage
{
    private const string URI_SCHEME = 'gs://';
    private const string INFO_TIME_CREATED = 'timeCreated';

    private ?Bucket $bucket = null;

    public function __construct(
        #[Autowire(env: 'GOOGLE_SERVICE_ACCOUNT_JSON_PATH')]
        private readonly string $serviceAccountJsonPath,
        #[Autowire(env: 'GCS_BUCKET_NAME')]
        private readonly string $bucketName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function upload(string $localPath, string $objectName): void
    {
        $file = fopen($localPath, 'rb');
        if (false === $file) {
            throw new \RuntimeException(\sprintf('Unable to open backup archive %s.', basename($localPath)));
        }

        $this->logger->info('Uploading backup to {uri}.', ['uri' => $this->uri($objectName)]);
        try {
            $this->bucket()->upload($file, ['name' => $objectName]);
        } finally {
            if (\is_resource($file)) {
                fclose($file);
            }
        }
        $this->logger->info('Uploaded {uri}.', ['uri' => $this->uri($objectName)]);
    }

    public function listObjects(string $prefix): array
    {
        $objects = [];
        foreach ($this->bucket()->objects(['prefix' => $prefix]) as $object) {
            $created = $object->info()[self::INFO_TIME_CREATED] ?? null;
            $objects[] = new BackupObject($object->name(), new \DateTimeImmutable(\is_string($created) ? $created : '@0'));
        }
        usort($objects, static fn (BackupObject $a, BackupObject $b): int => $a->createdAt <=> $b->createdAt);

        return $objects;
    }

    public function delete(string $objectName): void
    {
        $this->bucket()->object($objectName)->delete();
        $this->logger->info('Deleted {uri}.', ['uri' => $this->uri($objectName)]);
    }

    public function uri(string $objectName): string
    {
        return self::URI_SCHEME.$this->bucketName.'/'.$objectName;
    }

    private function bucket(): Bucket
    {
        if (null !== $this->bucket) {
            return $this->bucket;
        }
        if ('' === trim($this->serviceAccountJsonPath) || '' === trim($this->bucketName)) {
            throw new BackupConfigurationException('Backups are not configured: set GOOGLE_SERVICE_ACCOUNT_JSON_PATH and GCS_BUCKET_NAME.');
        }
        if (!is_file($this->serviceAccountJsonPath) || !is_readable($this->serviceAccountJsonPath)) {
            throw new BackupConfigurationException('The GCS service-account key file is missing or unreadable in the worker.');
        }

        return $this->bucket = (new StorageClient(['keyFilePath' => $this->serviceAccountJsonPath]))->bucket($this->bucketName);
    }
}
