<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Same rule as the owner's cron-jobs commands: per prefix, keep the newest three objects.
 */
final readonly class BackupRetention
{
    public const int KEEP = 3;

    public function __construct(
        private BackupStorage $storage,
    ) {
    }

    /** @return list<string> deleted object names */
    public function apply(string $prefix): array
    {
        $objects = $this->storage->listObjects($prefix);
        $deleted = [];
        foreach (\array_slice($objects, 0, max(0, \count($objects) - self::KEEP)) as $object) {
            $this->storage->delete($object->name);
            $deleted[] = $object->name;
        }

        return $deleted;
    }
}
