<?php

declare(strict_types=1);

namespace App\Index;

use App\Entity\IndexGeneration;
use App\Entity\IndexedMemory;
use App\Entity\IndexState;
use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Index\Source\CanonicalMemory;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Source\SourceSnapshot;
use App\Index\Store\IndexStoreFactory;
use App\Index\Store\IndexStores;
use App\Project\ProjectSourceException;
use App\Repository\IndexGenerationRepository;
use App\Repository\IndexedMemoryRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Owns one project's indexing checkpoint under a framework flock lock.
 *
 * Source reads stay short and read-only. Derived store writes happen outside
 * relational transactions. Relational parent manifests and cursors advance only
 * after both backends acknowledge the corresponding work.
 */
final class ProjectIndexer
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly IndexGenerationRepository $generationRepository,
        private readonly IndexedMemoryRepository $memoryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CanonicalSourceReader $sourceReader,
        private readonly IndexStoreFactory $storeFactory,
        private readonly MemoryChunker $chunker,
        private readonly IndexSettings $settings,
        private readonly LockFactory $indexExecutionLockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process one bounded indexing unit for a project.
     *
     * @return bool true when another continuation should be queued
     */
    public function process(int $projectId): bool
    {
        $lock = $this->indexExecutionLockFactory->createLock('project-index-exec:'.$projectId, 600.0);
        if (!$lock->acquire()) {
            $this->logger->info('Skipping project index because another worker owns the checkpoint.', [
                'projectId' => $projectId,
            ]);

            return false;
        }

        try {
            return $this->processLocked($projectId);
        } catch (\Throwable $exception) {
            $this->persistSafeFailure($projectId, $exception);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function processLocked(int $projectId): bool
    {
        $project = $this->projectRepository->find($projectId);
        if (null === $project) {
            $this->logger->info('Skipping project index because registration is missing.', [
                'projectId' => $projectId,
            ]);

            return false;
        }

        if (ProjectHealth::Ready !== $project->health) {
            $this->logger->info('Skipping project index until source discovery is ready.', [
                'projectId' => $projectId,
                'health' => $project->health->value,
            ]);

            return false;
        }

        $databasePath = $project->databasePath;
        if (null === $databasePath || '' === $databasePath) {
            throw new \RuntimeException('Project has no observational memory database path.');
        }

        $rootBinding = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
        $snapshot = $this->sourceReader->snapshot($databasePath);
        if (null === $snapshot->revision) {
            throw new \RuntimeException('Observational memory source revision is missing.');
        }

        $signature = $this->settings->signature();
        $generation = $this->selectOrCreateGeneration($project, $snapshot, $signature, $rootBinding);

        if (!$this->needsWork($generation, $snapshot)) {
            return false;
        }

        if (!$this->roundOpen($generation) && $this->shouldStartRound($generation, $snapshot)) {
            $this->beginRound($generation, $snapshot->revision);
            $generation = $this->requireGeneration((int) $generation->id);
        }

        if (null !== $generation->sourceCursor) {
            if ($this->processSourceBatch($generation, $snapshot)) {
                return true;
            }
            $generation = $this->requireGeneration((int) $generation->id);
        }

        if ($this->hasUnseenParents($generation)) {
            if ($this->cleanupUnseen($generation)) {
                return true;
            }
            $generation = $this->requireGeneration((int) $generation->id);
        }

        return $this->finishRound($generation, $snapshot, $rootBinding);
    }

    private function needsWork(IndexGeneration $generation, SourceSnapshot $snapshot): bool
    {
        if ($this->roundOpen($generation)) {
            return true;
        }

        if (null === $generation->committedRevision) {
            return true;
        }

        return $snapshot->revision > $generation->committedRevision;
    }

    private function shouldStartRound(IndexGeneration $generation, SourceSnapshot $snapshot): bool
    {
        if (null === $generation->committedRevision) {
            return true;
        }

        return $snapshot->revision > $generation->committedRevision;
    }

    private function roundOpen(IndexGeneration $generation): bool
    {
        if (null === $generation->scanRevision) {
            return false;
        }

        if (null !== $generation->sourceCursor || $this->hasUnseenParents($generation)) {
            return true;
        }

        return $generation->committedRevision !== $generation->scanRevision;
    }

    private function selectOrCreateGeneration(
        Project $project,
        SourceSnapshot $snapshot,
        string $signature,
        string $rootBinding,
    ): IndexGeneration {
        $latest = $this->generationRepository->findLatestForProject((int) $project->id);
        if (null !== $latest && $latest->matchesBinding($rootBinding, $snapshot->identity, $signature)) {
            // Revision rollback against the same filesystem identity still needs a
            // fresh generation so committed parents cannot mix eras.
            if (null !== $latest->committedRevision
                && null !== $snapshot->revision
                && $snapshot->revision < $latest->committedRevision) {
                return $this->createGeneration($project, $snapshot, $signature, $rootBinding);
            }

            return $latest;
        }

        return $this->createGeneration($project, $snapshot, $signature, $rootBinding);
    }

    private function createGeneration(
        Project $project,
        SourceSnapshot $snapshot,
        string $signature,
        string $rootBinding,
    ): IndexGeneration {
        $now = $this->clock->now();
        $generation = new IndexGeneration(
            project: $project,
            signature: $signature,
            sourceIdentity: $snapshot->identity,
            sourcePath: $snapshot->path,
            rootBinding: $rootBinding,
            now: $now,
        );
        $generation->markBuilding($now);
        $this->entityManager->persist($generation);
        $this->entityManager->flush();

        return $generation;
    }

    private function beginRound(IndexGeneration $generation, int $revision): void
    {
        $generationId = (int) $generation->id;
        $this->entityManager->wrapInTransaction(function () use ($generationId, $revision): void {
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation) {
                throw new \RuntimeException('Index generation disappeared before scan round start.');
            }
            $generation->beginScanRound($revision, $this->clock->now());
        });
    }

    private function processSourceBatch(IndexGeneration $generation, SourceSnapshot $snapshot): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        $cursor = $generation->sourceCursor;
        $batch = $this->sourceReader->fetchBatch($snapshot->path, $cursor, $this->settings->sourceBatchSize);

        if ([] === $batch) {
            $this->entityManager->wrapInTransaction(function () use ($generationId): void {
                $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
                if (null === $generation) {
                    return;
                }
                // End of keyset: clear cursor so cleanup/finish can run.
                $generation->advanceCursor(null, $this->clock->now());
            });

            return false;
        }

        $stores = $this->storeFactory->forGeneration($projectId, $generationId);
        $lastCursor = $cursor;
        foreach ($batch as $memory) {
            $this->reconcileParent($generation, $memory, $stores);
            $lastCursor = $memory->cursorKey();
        }

        $morePages = \count($batch) >= $this->settings->sourceBatchSize;
        $this->entityManager->wrapInTransaction(function () use ($generationId, $lastCursor, $morePages): void {
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation) {
                return;
            }
            // Keep a cursor only while more source pages remain. Final page clears
            // it so cleanup can detect unseen parents for this round.
            $generation->advanceCursor($morePages ? $lastCursor : null, $this->clock->now());
            $generation->refreshCounts(
                $this->memoryRepository->countParents($generationId),
                $this->memoryRepository->sumChunkCount($generationId),
                $this->clock->now(),
            );
        });

        return $morePages;
    }

    private function reconcileParent(IndexGeneration $generation, CanonicalMemory $memory, IndexStores $stores): void
    {
        $generationId = (int) $generation->id;
        $existing = $this->memoryRepository->findCommitted($generationId, $memory->kind, $memory->sourceId);
        $now = $this->clock->now();

        if (null !== $existing && $existing->contentSignature === $memory->contentSignature) {
            $existing->markSeen($generation->scanRound, $now);
            $this->entityManager->flush();

            return;
        }

        $chunks = array_values(array_filter(
            $this->chunker->split($memory->content),
            static fn (string $chunk): bool => '' !== trim($chunk),
        ));
        $chunkIds = $this->buildChunkIds($generation, $memory, $chunks);
        $documents = $this->buildDocuments($generation, $memory, $chunks, $chunkIds);
        if ([] !== $documents) {
            $stores->indexer->index($documents, ['chunk_size' => $this->settings->embeddingBatchSize]);
        }

        $obsolete = [];
        if (null !== $existing) {
            $obsolete = array_values(array_diff($existing->chunkIds, $chunkIds));
        }
        if ([] !== $obsolete) {
            $stores->combined->remove($obsolete);
        }

        $this->entityManager->wrapInTransaction(function () use ($generationId, $memory, $chunkIds): void {
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation) {
                throw new \RuntimeException('Index generation disappeared during parent commit.');
            }

            $now = $this->clock->now();
            $managed = $this->memoryRepository->findCommitted($generationId, $memory->kind, $memory->sourceId);
            if (null === $managed) {
                $row = new IndexedMemory(
                    generation: $generation,
                    kind: $memory->kind,
                    sourceId: $memory->sourceId,
                    sessionId: $memory->sessionId,
                    occurredAt: $memory->occurredAt,
                    contentSignature: $memory->contentSignature,
                    chunkIds: $chunkIds,
                    lastSeenRound: $generation->scanRound,
                    now: $now,
                );
                $this->entityManager->persist($row);
            } else {
                $managed->replaceContent(
                    sessionId: $memory->sessionId,
                    occurredAt: $memory->occurredAt,
                    contentSignature: $memory->contentSignature,
                    chunkIds: $chunkIds,
                    lastSeenRound: $generation->scanRound,
                    now: $now,
                );
            }
        });
    }

    /**
     * @param list<string> $chunks
     * @return list<string>
     */
    private function buildChunkIds(IndexGeneration $generation, CanonicalMemory $memory, array $chunks): array
    {
        $ids = [];
        foreach ($chunks as $position => $chunk) {
            $ids[] = hash('sha256', json_encode([
                (int) $generation->project->id,
                $generation->sourceIdentity,
                $generation->signature,
                $memory->kind,
                $memory->sourceId,
                $position,
                hash('sha256', $chunk),
            ], \JSON_THROW_ON_ERROR));
        }

        return $ids;
    }

    /**
     * @param list<string> $chunks
     * @param list<string> $chunkIds
     *
     * @return list<TextDocument>
     */
    private function buildDocuments(IndexGeneration $generation, CanonicalMemory $memory, array $chunks, array $chunkIds): array
    {
        if (\count($chunks) !== \count($chunkIds)) {
            throw new \RuntimeException('Chunk identity count diverged from chunk text count.');
        }

        $documents = [];
        foreach ($chunks as $position => $chunk) {
            $metadata = new Metadata([
                IndexFields::PROJECT_ID => (int) $generation->project->id,
                IndexFields::GENERATION_ID => (int) $generation->id,
                IndexFields::KIND => $memory->kind,
                IndexFields::SOURCE_ID => $memory->sourceId,
                IndexFields::SESSION_ID => $memory->sessionId,
                IndexFields::OCCURRED_AT => $memory->occurredAt,
                IndexFields::CONTENT_HASH => $memory->contentSignature,
                IndexFields::CHUNK_POSITION => $position,
            ]);
            $metadata->setText($chunk);

            $documents[] = new TextDocument($chunkIds[$position], $chunk, $metadata);
        }

        return $documents;
    }

    private function hasUnseenParents(IndexGeneration $generation): bool
    {
        if ($generation->scanRound < 1) {
            return false;
        }

        return [] !== $this->memoryRepository->findUnseen((int) $generation->id, $generation->scanRound, 1);
    }

    private function cleanupUnseen(IndexGeneration $generation): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        $unseen = $this->memoryRepository->findUnseen($generationId, $generation->scanRound, $this->settings->cleanupBatchSize);
        if ([] === $unseen) {
            return false;
        }

        $stores = $this->storeFactory->forGeneration($projectId, $generationId);
        $chunkIds = [];
        $memoryIds = [];
        foreach ($unseen as $memory) {
            foreach ($memory->chunkIds as $chunkId) {
                $chunkIds[] = $chunkId;
            }
            $memoryIds[] = (int) $memory->id;
        }

        if ([] !== $chunkIds) {
            $stores->combined->remove($chunkIds);
        }

        $this->entityManager->wrapInTransaction(function () use ($generationId, $memoryIds): void {
            foreach ($memoryIds as $memoryId) {
                $memory = $this->entityManager->find(IndexedMemory::class, $memoryId, LockMode::PESSIMISTIC_WRITE);
                if (null !== $memory) {
                    $this->entityManager->remove($memory);
                }
            }

            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null !== $generation) {
                $generation->refreshCounts(
                    $this->memoryRepository->countParents($generationId),
                    $this->memoryRepository->sumChunkCount($generationId),
                    $this->clock->now(),
                );
            }
        });

        return \count($unseen) >= $this->settings->cleanupBatchSize;
    }

    private function finishRound(IndexGeneration $generation, SourceSnapshot $snapshot, string $expectedRoot): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        if (null === $generation->scanRevision) {
            return false;
        }

        $scanRevision = $generation->scanRevision;
        $activated = false;

        $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $scanRevision, $expectedRoot, &$activated): void {
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            $project = $this->entityManager->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation || null === $project) {
                return;
            }

            $currentRoot = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
            if ($currentRoot !== $expectedRoot) {
                $this->logger->info('Discarding obsolete index publish after directory rebinding.', [
                    'projectId' => $projectId,
                    'generationId' => $generationId,
                ]);

                return;
            }

            $now = $this->clock->now();
            $generation->commitRevision(
                $scanRevision,
                $this->memoryRepository->countParents($generationId),
                $this->memoryRepository->sumChunkCount($generationId),
                $now,
            );
            $generation->markReady($now);

            if (!$generation->active) {
                $active = $this->generationRepository->findActiveForProject($projectId);
                if (null !== $active && $active->id !== $generation->id) {
                    $active->deactivate($now);
                    $active->markObsolete($now);
                }
                $generation->activate($now);
            }

            $project->markSuccessfulIndex($now);
            $activated = true;
        });

        if (!$activated) {
            return false;
        }

        // Current source may have advanced while this round was open.
        $fresh = $this->sourceReader->snapshot($snapshot->path);

        return null !== $fresh->revision && $fresh->revision > $scanRevision;
    }

    private function requireGeneration(int $generationId): IndexGeneration
    {
        $this->entityManager->clear();
        $generation = $this->generationRepository->find($generationId);
        if (null === $generation) {
            throw new \RuntimeException('Index generation disappeared during processing.');
        }

        return $generation;
    }

    private function persistSafeFailure(int $projectId, \Throwable $exception): void
    {
        try {
            $this->entityManager->clear();
            $generation = $this->generationRepository->findLatestForProject($projectId);
            if (null === $generation) {
                return;
            }

            $message = 'Indexing failed.';
            if ($exception instanceof ProjectSourceException) {
                $message = $exception->getMessage();
            } elseif ($exception instanceof \InvalidArgumentException) {
                $message = 'Indexing rejected invalid input.';
            }

            $generation->markError($this->clock->now(), $message);
            $this->entityManager->flush();
        } catch (\Throwable $nested) {
            $this->logger->error('Unable to persist sanitized index failure state.', [
                'projectId' => $projectId,
                'error' => $nested->getMessage(),
            ]);
        }
    }
}
