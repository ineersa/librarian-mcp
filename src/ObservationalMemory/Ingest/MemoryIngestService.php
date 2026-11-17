<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Message\ObserveMemorySessionMessage;
use App\ObservationalMemory\Compaction\ActiveMemoryRenderer;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Observe signal + session projection. Stores no conversation content: the Observer
 * job reads the journal range, and OM coverage makes repeated signals idempotent.
 */
final readonly class MemoryIngestService
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private ObservationRepository $observations,
        private MemoryGenerationRepository $generations,
        private ClockInterface $clock,
    ) {
    }

    public function observe(Project $project, MemoryObserveRequest $request): void
    {
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO om_sessions (project_id, source_kind, session_id, parent_session_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (project_id, source_kind, session_id) DO UPDATE SET updated_at = EXCLUDED.updated_at',
            [$project->id, $request->sourceKind->value, $request->sessionId, $request->parentSessionId, $timestamp, $timestamp],
        );
        $sessionPk = $this->findSessionPk($project, $request->sourceKind, $request->sessionId)
            ?? throw new \LogicException('Memory session row is missing after upsert.');

        $this->bus->dispatch(new ObserveMemorySessionMessage($sessionPk, $request->endSeq, $request->terminalStatus));
    }

    /**
     * Rendered active memory for compaction; empty string when nothing is active.
     * Bounded DB reads only: no model call, no journal read, no queue wait.
     */
    public function projection(Project $project, MemorySourceKind $sourceKind, string $sessionId): string
    {
        $sessionPk = $this->findSessionPk($project, $sourceKind, $sessionId);
        if (null === $sessionPk) {
            return '';
        }

        return trim(ActiveMemoryRenderer::render(
            $this->generations->listActiveReflections($sessionPk),
            $this->observations->listActiveCandidateObservations($sessionPk),
        ));
    }

    private function findSessionPk(Project $project, MemorySourceKind $sourceKind, string $sessionId): ?int
    {
        $pk = $this->connection->fetchOne(
            'SELECT id FROM om_sessions WHERE project_id = ? AND source_kind = ? AND session_id = ?',
            [$project->id, $sourceKind->value, $sessionId],
        );

        return false === $pk ? null : (int) $pk;
    }
}
