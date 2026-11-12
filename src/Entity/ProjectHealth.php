<?php

declare(strict_types=1);

namespace App\Entity;

enum ProjectHealth: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Ready = 'ready';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending scan',
            self::Scanning => 'Scanning sources',
            self::Ready => 'Sources discovered',
            self::Error => 'Discovery error',
        };
    }

    public function isPending(): bool
    {
        return self::Pending === $this;
    }

    public function isScanning(): bool
    {
        return self::Scanning === $this;
    }

    public function isReady(): bool
    {
        return self::Ready === $this;
    }

    public function isError(): bool
    {
        return self::Error === $this;
    }
}
