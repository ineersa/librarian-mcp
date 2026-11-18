<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemorySourceKind;

/**
 * Selects the transcript reader and source root for a memory session's client.
 */
final readonly class MemorySourceReaders
{
    public function __construct(
        private SessionEventsJournalReader $hatfield,
        private ClaudeTranscriptReader $claude,
    ) {
    }

    public function reader(MemorySourceKind $sourceKind): SessionSourceReader
    {
        return match ($sourceKind) {
            MemorySourceKind::Hatfield => $this->hatfield,
            MemorySourceKind::ClaudeCode => $this->claude,
        };
    }

    /**
     * Directory the kind's reader resolves session ids against; null while it is not
     * discovered (Hatfield) or not configured/present (Claude).
     */
    public function sourceRoot(MemorySourceKind $sourceKind, string $projectRoot, ?string $hatfieldSessionsPath): ?string
    {
        return match ($sourceKind) {
            MemorySourceKind::Hatfield => null === $hatfieldSessionsPath || '' === $hatfieldSessionsPath ? null : $hatfieldSessionsPath,
            MemorySourceKind::ClaudeCode => $this->claude->transcriptDirectory($projectRoot),
        };
    }
}
