<?php

declare(strict_types=1);

namespace App\Index;

use App\Entity\IndexedMemory;
use App\Entity\IndexGeneration;
use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Index\Source\CanonicalMemory;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Source\SourceSnapshot;
use App\Index\Store\IndexStoreFactory;
use App\Index\Store\IndexStores;
use App\Repository\IndexedMemoryRepository;
use App\Repository\IndexGenerationRepository;
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
    public const int SOURCE_BATCH_SIZE = 20;
    public const int CLEANUP_BATCH_SIZE = 20;
    public const int EMBEDDING_BATCH_SIZE = 4;

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
        } catch (IndexException $exception) {
            $this->persistSafeFailure($projectId, $exception);
            throw $exception;
        } catch (\Throwable $exception) {
            $safe = IndexException::sanitized('Indexing failed.', $exception);
            $this->persistSafeFailure($projectId, $safe);
            throw $safe;
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
            throw IndexException::sanitized('Project has no observational memory database path.');
        }

        $rootBinding = $this->rootBinding($project);
        $snapshot = $this->sourceReader->snapshot($databasePath);
        if (null === $snapshot->revision) {
            throw IndexException::sanitized('Observational memory source revision is missing.');
        }

        $signature = $this->settings->signature();
        $generation = $this->selectOrCreateGeneration($project, $snapshot, $signature, $rootBinding);

        if (!$this->needsWork($generation, $snapshot)) {
            return false;
        }

        if (!$this->roundOpen($generation) && $this->shouldStartRound($generation, $snapshot)) {
            $this->beginRound($generation, $snapshot->revision);
            $generation = $this->requireGeneration((int) $generation->id);
            $this->initializeFreshStoresIfNeeded($generation);
        }

        if (null !== $generation->sourceCursor) {
            if ($this->processSourceBatch($generation, $snapshot, $rootBinding)) {
                return true;
            }
            $generation = $this->requireGeneration((int) $generation->id);
        }

        if ($this->hasUnseenParents($generation)) {
            if ($this->cleanupUnseen($generation, $rootBinding)) {
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
                throw IndexException::sanitized('Index generation disappeared before scan round start.');
            }
            $generation->beginScanRound($revision, $this->clock->now());
        });
    }

    private function initializeFreshStoresIfNeeded(IndexGeneration $generation): void
    {
        // Fresh generation with no committed parents/cursor work yet: clear any
        // orphaned Chroma/lexical state left after browser DB recreation.
        if (null !== $generation->committedRevision || 0 !== $generation->parentCount || 0 !== $generation->chunkCount) {
            return;
        }
        if ('' !== ($generation->sourceCursor ?? '')) {
            return;
        }
        if ($generation->active) {
            return;
        }

        $stores = $this->storeFactory->forGeneration((int) $generation->project->id, (int) $generation->id);
        $stores->combined->clear(['batch_size' => 100]);
    }

    private function processSourceBatch(IndexGeneration $generation, SourceSnapshot $snapshot, string $expectedRoot): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        $cursor = $generation->sourceCursor;
        $batch = $this->sourceReader->fetchBatch($snapshot->path, $cursor, self::SOURCE_BATCH_SIZE);

        if ([] === $batch) {
            $this->commitCursor($generationId, $projectId, $expectedRoot, $snapshot->identity, null);

            return false;
        }

        $stores = $this->storeFactory->forGeneration($projectId, $generationId);
        $lastCursor = $cursor;
        foreach ($batch as $memory) {
            $this->reconcileParent($generation, $memory, $stores, $expectedRoot, $snapshot->identity);
            $lastCursor = $memory->cursorKey();
        }

        $morePages = \count($batch) >= self::SOURCE_BATCH_SIZE;
        $this->commitCursor(
            $generationId,
            $projectId,
            $expectedRoot,
            $snapshot->identity,
            $morePages ? $lastCursor : null,
        );

        return $morePages;
    }

    private function reconcileParent(
        IndexGeneration $generation,
        CanonicalMemory $memory,
        IndexStores $stores,
        string $expectedRoot,
        string $expectedSourceIdentity,
    ): void {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        $existing = $this->memoryRepository->findCommitted($generationId, $memory->kind->value, $memory->sourceId);
        $now = $this->clock->now();

        if (null !== $existing && $existing->contentSignature === $memory->contentSignature) {
            $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $expectedRoot, $expectedSourceIdentity, $memory, $now): void {
                $this->assertCurrentBinding($projectId, $generationId, $expectedRoot, $expectedSourceIdentity);
                $managed = $this->memoryRepository->findCommitted($generationId, $memory->kind->value, $memory->sourceId);
                if (null === $managed) {
                    return;
                }
                $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
                if (null === $generation) {
                    return;
                }
                $managed->markSeen($generation->scanRound, $now);
            });

            return;
        }

        $chunks = array_values(array_filter(
            $this->chunker->split($memory->content),
            static fn (string $chunk): bool => '' !== trim($chunk),
        ));
        $chunkIds = $this->buildChunkIds($generation, $memory, $chunks);
        $documents = $this->buildDocuments($generation, $memory, $chunks, $chunkIds);
        if ([] !== $documents) {
            $stores->indexer->index($documents, ['chunk_size' => self::EMBEDDING_BATCH_SIZE]);
        }

        $obsolete = [];
        if (null !== $existing) {
            $obsolete = array_values(array_diff($existing->chunkIds, $chunkIds));
        }
        if ([] !== $obsolete) {
            $stores->combined->remove($obsolete);
        }

        $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $expectedRoot, $expectedSourceIdentity, $memory, $chunkIds): void {
            $this->assertCurrentBinding($projectId, $generationId, $expectedRoot, $expectedSourceIdentity);
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation) {
                throw IndexException::sanitized('Index generation disappeared during parent commit.');
            }

            $now = $this->clock->now();
            $managed = $this->memoryRepository->findCommitted($generationId, $memory->kind->value, $memory->sourceId);
            if (null === $managed) {
                $row = new IndexedMemory(
                    generation: $generation,
                    kind: $memory->kind->value,
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
     *
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
                $memory->kind->value,
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
            throw IndexException::sanitized('Chunk identity count diverged from chunk text count.');
        }

        $documents = [];
        foreach ($chunks as $position => $chunk) {
            $metadata = new Metadata([
                IndexFields::PROJECT_ID => (int) $generation->project->id,
                IndexFields::GENERATION_ID => (int) $generation->id,
                IndexFields::KIND => $memory->kind->value,
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

    private function cleanupUnseen(IndexGeneration $generation, string $expectedRoot): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        $expectedSourceIdentity = $generation->sourceIdentity;
        $unseen = $this->memoryRepository->findUnseen($generationId, $generation->scanRound, self::CLEANUP_BATCH_SIZE);
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

        $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $expectedRoot, $expectedSourceIdentity, $memoryIds): void {
            $this->assertCurrentBinding($projectId, $generationId, $expectedRoot, $expectedSourceIdentity);

            foreach ($memoryIds as $memoryId) {
                $memory = $this->entityManager->find(IndexedMemory::class, $memoryId, LockMode::PESSIMISTIC_WRITE);
                if (null !== $memory) {
                    $this->entityManager->remove($memory);
                }
            }

            // Flush removals before counting so parent/chunk totals are current.
            $this->entityManager->flush();

            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null !== $generation) {
                $generation->refreshCounts(
                    $this->memoryRepository->countParents($generationId),
                    $this->memoryRepository->sumChunkCount($generationId),
                    $this->clock->now(),
                );
            }
        });

        return \count($unseen) >= self::CLEANUP_BATCH_SIZE;
    }

    private function finishRound(IndexGeneration $generation, SourceSnapshot $snapshot, string $expectedRoot): bool
    {
        $generationId = (int) $generation->id;
        $projectId = (int) $generation->project->id;
        if (null === $generation->scanRevision) {
            return false;
        }

        $scanRevision = $generation->scanRevision;
        $expectedSourceIdentity = $generation->sourceIdentity;
        $activated = false;

        // Source reads stay outside the short relational publish transaction.
        $freshSnapshot = $this->sourceReader->snapshot($snapshot->path);
        if ($freshSnapshot->identity !== $expectedSourceIdentity) {
            $this->logger->info('Discarding obsolete index publish after source identity replacement.', [
                'projectId' => $projectId,
                'generationId' => $generationId,
            ]);

            return false;
        }

        $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $scanRevision, $expectedRoot, $expectedSourceIdentity, &$activated): void {
            $this->assertCurrentBinding($projectId, $generationId, $expectedRoot, $expectedSourceIdentity);

            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            $project = $this->entityManager->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation || null === $project) {
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

        return null !== $freshSnapshot->revision && $freshSnapshot->revision > $scanRevision;
    }

    private function commitCursor(
        int $generationId,
        int $projectId,
        string $expectedRoot,
        string $expectedSourceIdentity,
        ?string $cursor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($generationId, $projectId, $expectedRoot, $expectedSourceIdentity, $cursor): void {
            $this->assertCurrentBinding($projectId, $generationId, $expectedRoot, $expectedSourceIdentity);
            $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
            if (null === $generation) {
                return;
            }
            $generation->advanceCursor($cursor, $this->clock->now());
            $generation->refreshCounts(
                $this->memoryRepository->countParents($generationId),
                $this->memoryRepository->sumChunkCount($generationId),
                $this->clock->now(),
            );
        });
    }

    private function assertCurrentBinding(
        int $projectId,
        int $generationId,
        string $expectedRoot,
        string $expectedSourceIdentity,
    ): void {
        // find(..., PESSIMISTIC_WRITE) does not refresh an already-managed Project
        // after external store/model work. Clear first so root/source checks see
        // the latest committed binding before publish.
        $this->entityManager->clear();

        $project = $this->entityManager->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);
        $generation = $this->entityManager->find(IndexGeneration::class, $generationId, LockMode::PESSIMISTIC_WRITE);
        if (null === $project || null === $generation) {
            throw IndexException::sanitized('Project or index generation disappeared during commit.');
        }

        $currentRoot = $this->rootBinding($project);
        if ($currentRoot !== $expectedRoot) {
            throw IndexException::sanitized('Project directory rebinding invalidated this index checkpoint.');
        }

        if ($generation->sourceIdentity !== $expectedSourceIdentity) {
            throw IndexException::sanitized('Source identity replacement invalidated this index checkpoint.');
        }
    }

    private function rootBinding(Project $project): string
    {
        return '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
    }

    private function requireGeneration(int $generationId): IndexGeneration
    {
        $this->entityManager->clear();
        $generation = $this->generationRepository->find($generationId);
        if (null === $generation) {
            throw IndexException::sanitized('Index generation disappeared during processing.');
        }

        return $generation;
    }

    private function persistSafeFailure(int $projectId, IndexException $exception): void
    {
        try {
            $this->entityManager->clear();
            $generation = $this->generationRepository->findLatestForProject($projectId);
            if (null === $generation) {
                return;
            }

            $generation->markError($this->clock->now(), $exception->getMessage());
            $this->entityManager->flush();
        } catch (\Throwable) {
            $this->logger->error('Unable to persist sanitized index failure state.', [
                'projectId' => $projectId,
            ]);
        }
    }
}
