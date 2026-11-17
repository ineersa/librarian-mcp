<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

/**
 * Typed observe-signal contract failures (plan 09).
 */
enum MemoryIngestErrorCode: string
{
    case InvalidRequest = 'invalid_request';
    case UnsupportedContractVersion = 'unsupported_contract_version';
    case UnsupportedSourceKind = 'unsupported_source_kind';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidRequest, self::UnsupportedContractVersion => 400,
            self::UnsupportedSourceKind => 422,
        };
    }
}
