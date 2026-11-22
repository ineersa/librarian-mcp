<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * Symfony AI bridge used to invoke the selected OM model.
 */
enum MemoryModelBridge: string
{
    case Generic = 'generic';
    case Mistral = 'mistral';
}
