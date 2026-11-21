<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\ObservationalMemory\MemorySettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class ObserverCoverageReader
{
    public const string SESSION_QUERY = 'coverageSession';
    public const string BEFORE_QUERY = 'coverageBefore';
    public const string FAILURE_QUERY = 'coverageFailure';
    private const int PAGE_SIZE = 25;

    public function __construct(
        private Connection $connection,
        private ObservationRepository $observations,
        private MemorySettings $settings,
    ) {
    }

    /** @return array{sessions: array<int, ObserverCoverage>, next: ?int} */
    public function forProject(int $projectId, int $session, int $before): array
    {
        $ids = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM om_sessions WHERE project_id = ?'.($session > 0 ? ' AND id = ?' : ($before > 0 ? ' AND id < ?' : '')).' ORDER BY id DESC LIMIT '.(self::PAGE_SIZE + 1),
            $session > 0 || $before > 0 ? [$projectId, $session > 0 ? $session : $before] : [$projectId],
        ));
        $idsOnPage = \array_slice($ids, 0, self::PAGE_SIZE);

        return [
            'sessions' => $this->forSessions($idsOnPage),
            'next' => \count($ids) > self::PAGE_SIZE ? $idsOnPage[self::PAGE_SIZE - 1] : null,
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, ObserverCoverage>
     */
    public function forSessions(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $coverage = [];
        foreach ($ids as $id) {
            $coverage[$id] = new ObserverCoverage();
        }
        foreach ($this->observations->completeCoverageChunks($ids, $this->settings->rendererVersion, $this->settings->observerSchemaVersion) as $row) {
            $coverage[(int) $row['memory_session_id']]->includeChunk((int) $row['source_start_seq'], (int) $row['source_end_seq']);
        }

        return $coverage;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function projectIdsForSessions(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchAllKeyValue(
            'SELECT id, project_id FROM om_sessions WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        ));
    }
}
