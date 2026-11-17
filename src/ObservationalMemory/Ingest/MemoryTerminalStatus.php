<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

/**
 * Turn outcome carried to the Observer (OM ObserveBoundaryTerminalHook statuses).
 */
enum MemoryTerminalStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
