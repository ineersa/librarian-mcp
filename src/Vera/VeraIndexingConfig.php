<?php

declare(strict_types=1);

namespace App\Vera;

/**
 * Immutable value object representing vera indexing configuration.
 */
readonly class VeraIndexingConfig
{
    /**
     * @param array<string> $excludePatterns One glob pattern per entry
     */
    public function __construct(
        public array $excludePatterns = [],
        public bool $noDefaultExcludes = false,
    ) {
    }

    /**
     * @param array{excludePatterns?: array<string>, noDefaultExcludes?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            excludePatterns: $data['excludePatterns'] ?? [],
            noDefaultExcludes: $data['noDefaultExcludes'] ?? false,
        );
    }

    /** @return array{excludePatterns: array<string>, noDefaultExcludes: bool} */
    public function toArray(): array
    {
        return [
            'excludePatterns' => $this->excludePatterns,
            'noDefaultExcludes' => $this->noDefaultExcludes,
        ];
    }
}
