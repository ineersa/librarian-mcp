<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Client transcript family that produced a memory session (plan 09 ingest contract).
 */
enum MemorySourceKind: string
{
    case Hatfield = 'hatfield';
    case ClaudeCode = 'claude_code';
}
