<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupObject
{
    public function __construct(
        public string $name,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
