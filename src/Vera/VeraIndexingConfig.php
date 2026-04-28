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
        public bool $noIgnore = false,
        public bool $noDefaultExcludes = false,
    ) {
    }

    /**
     * @param array{excludePatterns?: array<string>, noIgnore?: bool, noDefaultExcludes?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            excludePatterns: $data['excludePatterns'] ?? [],
            noIgnore: $data['noIgnore'] ?? false,
            noDefaultExcludes: $data['noDefaultExcludes'] ?? false,
        );
    }

    /** @return array{excludePatterns: array<string>, noIgnore: bool, noDefaultExcludes: bool} */
    public function toArray(): array
    {
        return [
            'excludePatterns' => $this->excludePatterns,
            'noIgnore' => $this->noIgnore,
            'noDefaultExcludes' => $this->noDefaultExcludes,
        ];
    }
}
