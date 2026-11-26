<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

use App\Entity\LibrarianSettings;
use App\Entity\MemorySourceKind;
use App\ObservationalMemory\MemorySettings;
use App\Repository\LibrarianSettingsRepository;

/**
 * Per-source OM token limits: the saved admin settings, or the env baseline when none are saved.
 *
 * Resolved when a job runs, so a settings change also applies to jobs already queued.
 */
final readonly class MemoryTokenLimits
{
    public function __construct(
        private LibrarianSettingsRepository $settings,
        private MemorySettings $baseline,
    ) {
    }

    public function apply(PinnedMemorySettings $pinned, MemorySourceKind $sourceKind): PinnedMemorySettings
    {
        $limits = ($this->settings->current() ?? $this->defaults())->tokenLimitsFor($sourceKind);

        return $pinned->withTokenLimits($limits['reflectAfterObservationTokens'], $limits['observationsMaxTokens']);
    }

    /** Unsaved settings holding today's env limits for every source kind. */
    public function defaults(): LibrarianSettings
    {
        return new LibrarianSettings($this->baseline->reflectAfterObservationTokens, $this->baseline->observationsMaxTokens);
    }
}
