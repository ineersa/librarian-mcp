<?php

declare(strict_types=1);

namespace App\Index\Source;

/**
 * Canonical OM memory kinds retained for indexing (Librarian-owned memory tables).
 */
enum MemoryKind: string
{
    case Observation = 'observation';
    case Reflection = 'reflection';

    /**
     * @return list<self>
     */
    public static function keysetOrder(): array
    {
        return [self::Observation, self::Reflection];
    }

    public function idColumn(): string
    {
        return match ($this) {
            self::Observation => 'observation_id',
            self::Reflection => 'reflection_id',
        };
    }

    public function table(): string
    {
        return match ($this) {
            self::Observation => 'om_observations',
            self::Reflection => 'om_reflections',
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Observation => 'timestamp',
            self::Reflection => 'created_at',
        };
    }
}
