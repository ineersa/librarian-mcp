<?php

declare(strict_types=1);

namespace App\Entity;

enum IndexState: string
{
    case Building = 'building';
    case Updating = 'updating';
    case Ready = 'ready';
    case Error = 'error';
    case Obsolete = 'obsolete';

    public function label(): string
    {
        return match ($this) {
            self::Building => 'Building',
            self::Updating => 'Updating',
            self::Ready => 'Ready',
            self::Error => 'Error',
            self::Obsolete => 'Obsolete',
        };
    }

    public function isUsable(): bool
    {
        return self::Ready === $this || self::Updating === $this;
    }

    public function isInProgress(): bool
    {
        return self::Building === $this || self::Updating === $this;
    }
}
