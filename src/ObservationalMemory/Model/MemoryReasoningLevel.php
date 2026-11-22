<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * Admin-selectable reasoning levels. Provider-default-on is never labelled "off".
 */
enum MemoryReasoningLevel: string
{
    case Default = 'default';
    case Off = 'off';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Provider default',
            self::Off => 'Off',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Xhigh => 'Extra high',
            self::Max => 'Max',
        };
    }
}
