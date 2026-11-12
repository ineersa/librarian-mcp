<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Read-only discovery outcome for one project scan.
 */
final class ProjectDiscoveryResult
{
    /**
     * @param list<array{code: string, message: string}> $issues
     * @param array<string, mixed>                       $details
     */
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $error,
        public readonly ?string $databasePath,
        public readonly ?string $sessionsPath,
        public readonly ?string $schemaVersion,
        public readonly int $observationCount,
        public readonly int $reflectionCount,
        public readonly int $sessionCount,
        public readonly ?int $sourceRevision,
        public readonly int $sourceChangeCount,
        public readonly array $issues,
        public readonly array $details,
    ) {
    }

    /**
     * @param list<array{code: string, message: string}> $issues
     * @param array<string, mixed>                       $details
     */
    public static function error(
        string $error,
        array $issues = [],
        array $details = [],
        ?string $databasePath = null,
        ?string $sessionsPath = null,
        ?string $schemaVersion = null,
        int $observationCount = 0,
        int $reflectionCount = 0,
        int $sessionCount = 0,
        ?int $sourceRevision = null,
        int $sourceChangeCount = 0,
    ): self {
        return new self(
            successful: false,
            error: $error,
            databasePath: $databasePath,
            sessionsPath: $sessionsPath,
            schemaVersion: $schemaVersion,
            observationCount: $observationCount,
            reflectionCount: $reflectionCount,
            sessionCount: $sessionCount,
            sourceRevision: $sourceRevision,
            sourceChangeCount: $sourceChangeCount,
            issues: $issues,
            details: $details,
        );
    }

    /**
     * @param array<string, mixed>                       $details
     * @param list<array{code: string, message: string}> $issues
     */
    public static function ready(
        string $databasePath,
        string $sessionsPath,
        string $schemaVersion,
        int $observationCount,
        int $reflectionCount,
        int $sessionCount,
        ?int $sourceRevision,
        int $sourceChangeCount,
        array $details,
        array $issues = [],
    ): self {
        return new self(
            successful: true,
            error: null,
            databasePath: $databasePath,
            sessionsPath: $sessionsPath,
            schemaVersion: $schemaVersion,
            observationCount: $observationCount,
            reflectionCount: $reflectionCount,
            sessionCount: $sessionCount,
            sourceRevision: $sourceRevision,
            sourceChangeCount: $sourceChangeCount,
            issues: $issues,
            details: $details,
        );
    }
}
