<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Persisted `om_memory_generations.failure_code` values (OM failure codes).
 */
enum MemoryGenerationFailureCode: string
{
    case NoActiveMemory = 'no_active_memory';
    case StaleObservationSet = 'stale_observation_set';
    case TransientRuntime = 'transient_runtime';
    case Conflict = 'conflict';
}
