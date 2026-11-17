<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MemoryIngestException extends \RuntimeException
{
    public function __construct(
        public readonly MemoryIngestErrorCode $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
