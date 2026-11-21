<?php

declare(strict_types=1);

namespace App\Operations;

enum LogSource: string
{
    case Web = 'web';
    case Worker = 'worker';
    case OmWorker = 'om-worker';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Worker => 'General worker',
            self::OmWorker => 'OM worker',
        };
    }
}
