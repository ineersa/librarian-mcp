<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Ported from OM MemoryGenerationRepository STATUS_* constants.
 */
enum MemoryGenerationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
