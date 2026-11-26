<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Index\ProjectIndexManager;
use App\Message\ObserveMemorySessionMessage;
use App\ObservationalMemory\Compaction\ActiveMemoryRenderer;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\MemoryRevision;
use App\ObservationalMemory\Storage\ObservationRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Observe signal, session projection and session deletion. Stores no conversation content: the Observer
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
        private ProjectIndexManager $projectIndexManager,
        private MemoryModelSelectionService $selections,
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

        $settings = $this->selections->currentPinned();
        $this->bus->dispatch(new ObserveMemorySessionMessage(
            ...$settings->toMessagePayload(),
            sessionPk: $sessionPk,
            terminalEndSeq: $request->endSeq,
            terminalStatus: $request->terminalStatus,
        ));
    }

    /**
     * Gap-free observed end seq of a session under the current renderer/schema versions;
     * null when the session is unknown or nothing from seq 1 is covered yet.
     */
    public function coveredEndSeq(Project $project, MemorySourceKind $sourceKind, string $sessionId): ?int
    {
        $sessionPk = $this->findSessionPk($project, $sourceKind, $sessionId);
        if (null === $sessionPk) {
            return null;
        }
        $settings = $this->selections->currentPinned();

        return $this->observations->contiguousCoveredEndSeq($sessionPk, $settings->rendererVersion, $settings->observerSchemaVersion);
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

    /**
     * Forget a session the client deleted: its observations, reflections, coverage and
     * generations cascade with the session row; the revision bump plus a queued index
     * pass drop the session's chunks. Idempotent: false when absent.
     *
     * An in-flight Observer/Reflector job for the session fails its writes on the
     * missing session and is inert on retry, so no session lock is taken here.
     */
    public function deleteSession(Project $project, MemorySourceKind $sourceKind, string $sessionId): bool
    {
        $deleted = $this->connection->transactional(static function (Connection $connection) use ($project, $sourceKind, $sessionId): bool {
            $deleted = $connection->executeStatement(
                'DELETE FROM om_sessions WHERE project_id = ? AND source_kind = ? AND session_id = ?',
                [$project->id, $sourceKind->value, $sessionId],
            );
            if (0 === $deleted) {
                return false;
            }
            MemoryRevision::bumpForProject($connection, (int) $project->id);

            return true;
        });
        if ($deleted) {
            // Drop the session's chunks now rather than waiting for the next discovery scan.
            $this->projectIndexManager->queueId((int) $project->id);
        }

        return $deleted;
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
