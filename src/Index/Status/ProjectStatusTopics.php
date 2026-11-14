<?php

declare(strict_types=1);

namespace App\Index\Status;

/**
 * Mercure topics for project source/index status Turbo Streams.
 *
 * Uses exact topic strings so the installed Mercure 0.7 hub / FrankenPHP
 * compatibility path stays on the protocol 0.x "topic" query parameter.
 */
final class ProjectStatusTopics
{
    public const string LIST_STATUS = 'https://librarian.local/projects/status';
    private const string PROJECT_STATUS = 'https://librarian.local/projects/%d/status';

    public static function forProject(int $projectId): string
    {
        if ($projectId < 1) {
            throw new \InvalidArgumentException('Project id must be positive.');
        }

        return \sprintf(self::PROJECT_STATUS, $projectId);
    }
}
