<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Mirrors Hatfield AppConfigLoader overlay semantics without booting Hatfield.
 *
 * Associative maps deep-overlay; lists replace wholesale; scalars/null replace.
 */
final class HatfieldSettingsOverlay
{
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    public function overlay(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                if ($this->isAssoc($value) && $this->isAssoc($base[$key])) {
                    $base[$key] = $this->overlay($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }
}
