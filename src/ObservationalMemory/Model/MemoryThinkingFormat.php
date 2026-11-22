<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * Hatfield-compatible thinking request shapes used by OM agent calls.
 */
enum MemoryThinkingFormat: string
{
    case None = 'none';
    case LlamaCpp = 'llama_cpp';
    case Mistral = 'mistral';
    case Zai = 'zai';
}
