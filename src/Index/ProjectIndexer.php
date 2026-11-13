<?php

declare(strict_types=1);

namespace App\Index;

use App\Entity\IndexedMemory;
use App\Entity\IndexGeneration;
use App\Entity\IndexState;
use App\Entity\Project;
use App\Entity\ProjectHealth;
use App\Index\Source\CanonicalMemory;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Source\SourceSnapshot;
use App\Index\Store\IndexStoreFactory;
use App\Index\Status\ProjectStatusPublisher;
use App\Repository\IndexedMemoryRepository;
use App\Repository\IndexGenerationRepository;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Bridge\Postgres\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Bounded reconciliation under a per-project execution lock. Source reads and
 * embeddings precede the short transaction committing chunks, manifests and cursor.
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
        private readonly ManagerRegistry $managerRegistry,
        private readonly CanonicalSourceReader $sourceReader,
        private readonly IndexStoreFactory $storeFactory,
        private readonly VectorizerInterface $vectorizer,
        private readonly MemoryChunker $chunker,
        private readonly IndexSettings $settings,
        private readonly LockFactory $indexExecutionLockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ProjectStatusPublisher $projectStatusPublisher,
    ) {
    }

    /** Returns true when another bounded unit should be queued. */
    public function process(int $projectId): bool
    {
        $lock = $this->indexExecutionLockFactory->createLock('project-index-exec:'.$projectId, 600.0);
        if (!$lock->acquire()) {
            return false;
        }

        try {
            return $this->processLocked($projectId);
        } catch (\Throwable $exception) {
            $safe = $exception instanceof IndexException ? $exception : IndexException::sanitized('Indexing failed.', $exception);
            $this->persistSafeFailure($projectId, $safe);
            throw $safe;
        } finally {
            $lock->release();
        }
    }

    private function processLocked(int $projectId): bool
    {
        $project = $this->projectRepository->find($projectId);
        if (null === $project || ProjectHealth::Ready !== $project->health) {
            return false;
        }
        if (null === $project->databasePath || '' === $project->databasePath) {
            throw IndexException::sanitized('Project has no observational memory database path.');
        }

        $snapshot = $this->sourceReader->snapshot($project->databasePath);
        $generation = $this->selectOrCreateGeneration($project, $snapshot);
        if (null === $generation->scanRevision) {
            if ($generation->committedRevision === $snapshot->revision) {
                if (IndexState::Error === $generation->state) {
                    $generation->markReady($this->clock->now());
                    $this->entityManager->flush();
                    $this->projectStatusPublisher->publish($projectId);
                }
                $this->removeObsoleteGenerations($projectId, (int) $generation->id);

                return false;
            }
            $generation->beginScanRound($snapshot->revision, $this->clock->now());
            $this->entityManager->flush();
            $this->projectStatusPublisher->publish($projectId);
        }

        $store = $this->storeFactory->forGeneration($projectId, (int) $generation->id, initialize: false);
        if (null !== $generation->sourceCursor) {
            $this->processSourceBatch($generation, $snapshot, $store);

            return true;
        }

        $unseen = $this->memoryRepository->findUnseen((int) $generation->id, $generation->scanRound, self::CLEANUP_BATCH_SIZE);
        if ([] !== $unseen) {
            $this->removeUnseen($generation, $store, $unseen);

            return true;
        }

        return $this->finishRound($generation);
    }

    private function selectOrCreateGeneration(Project $project, SourceSnapshot $snapshot): IndexGeneration
    {
        $signature = $this->settings->signature();
        $root = $this->rootBinding($project);
        $latest = $this->generationRepository->findLatestForProject((int) $project->id);
        if (null !== $latest && $latest->matchesBinding($root, $snapshot->identity, $signature)
            && $snapshot->revision >= ($latest->committedRevision ?? 0)
            && $snapshot->revision >= ($latest->scanRevision ?? 0)) {
            return $latest;
        }

        $generation = new IndexGeneration($project, $signature, $snapshot->identity, $snapshot->path, $root, $this->clock->now());
        // PostgreSQL DDL and the generation row commit together; retries cannot
        // observe a registered generation without its provisioned store.
        $this->entityManager->wrapInTransaction(function () use ($generation, $project): void {
            $this->entityManager->persist($generation);
            $this->entityManager->flush();
            $this->storeFactory->forGeneration((int) $project->id, (int) $generation->id);
        });
        $this->projectStatusPublisher->publish((int) $project->id);

        return $generation;
    }

    private function processSourceBatch(IndexGeneration $generation, SourceSnapshot $snapshot, Store $store): void
    {
        $generationId = (int) $generation->id;
        $batch = $this->sourceReader->fetchBatch($snapshot->path, $generation->sourceCursor, self::SOURCE_BATCH_SIZE);
        $parents = $this->memoryRepository->findParents($generationId, array_map(static fn (CanonicalMemory $memory): string => $memory->sourceId, $batch));
        $byKey = [];
        foreach ($parents as $parent) {
            $byKey[$parent->kind."\0".$parent->sourceId] = $parent;
        }

        $unchangedIds = [];
        $changed = [];
        $documents = [];
        foreach ($batch as $memory) {
            $existing = $byKey[$memory->kind->value."\0".$memory->sourceId] ?? null;
            if (null !== $existing && $existing->contentSignature === $memory->contentSignature) {
                $unchangedIds[] = (int) $existing->id;
                continue;
            }
            $parentDocuments = $this->buildDocuments($generation, $memory);
            $changed[] = [$memory, array_map(static fn (TextDocument $document): string => (string) $document->getId(), $parentDocuments)];
            array_push($documents, ...$parentDocuments);
        }

        // Native Vectorizer makes provider requests before opening a write transaction.
        $vectors = [];
        foreach (array_chunk($documents, self::EMBEDDING_BATCH_SIZE) as $chunk) {
            $result = $this->vectorizer->vectorize($chunk);
            if (!\is_array($result)) {
                throw IndexException::sanitized('Document vectorization did not return a batch.');
            }
            foreach ($result as $document) {
                if (!$document instanceof VectorDocumentInterface) {
                    throw IndexException::sanitized('Document vectorization returned an unexpected result.');
                }
                $vectors[] = $document;
            }
        }

        $cursor = self::SOURCE_BATCH_SIZE === \count($batch) ? $batch[array_key_last($batch)]->cursorKey() : null;
        $this->entityManager->clear();
        $this->entityManager->wrapInTransaction(function () use ($generation, $generationId, $store, $unchangedIds, $changed, $vectors, $cursor): void {
            $managedGeneration = $this->lockCurrentGeneration($generation);
            if ([] !== $vectors) {
                $store->add($vectors);
            }
            $this->memoryRepository->markSeen($unchangedIds, $managedGeneration->scanRound);
            foreach ($changed as [$memory, $chunkIds]) {
                $existing = $this->memoryRepository->findCommitted($generationId, $memory->kind->value, $memory->sourceId);
                $previousIds = $existing->chunkIds ?? [];
                $this->removeChunks($store, array_values(array_diff($previousIds, $chunkIds)));
                $managedGeneration->adjustCounts(null === $existing ? 1 : 0, \count($chunkIds) - \count($previousIds));
                if (null === $existing) {
                    $this->entityManager->persist(new IndexedMemory($managedGeneration, $memory->kind->value, $memory->sourceId, $memory->contentSignature, $chunkIds, $managedGeneration->scanRound));
                } else {
                    $existing->replaceContent($memory->contentSignature, $chunkIds, $managedGeneration->scanRound);
                }
            }
            $managedGeneration->advanceCursor($cursor, $this->clock->now());
        });
        $this->projectStatusPublisher->publish((int) $generation->project->id);
    }

    /** @return list<TextDocument> */
    private function buildDocuments(IndexGeneration $generation, CanonicalMemory $memory): array
    {
        $documents = [];
        foreach ($this->chunker->split($memory->content) as $position => $chunk) {
            if ('' === trim($chunk)) {
                continue;
            }
            // UUIDs are confined to the official store schema, as approved by the owner.
            $id = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), json_encode([
                $generation->id, $memory->kind->value, $memory->sourceId, $memory->contentSignature, $position,
            ], \JSON_THROW_ON_ERROR))->toRfc4122();
            $documents[] = new TextDocument($id, $chunk, new Metadata([
                IndexFields::PROJECT_ID => (int) $generation->project->id,
                IndexFields::GENERATION_ID => (int) $generation->id,
                IndexFields::KIND => $memory->kind->value,
                IndexFields::SOURCE_ID => $memory->sourceId,
                IndexFields::SESSION_ID => $memory->sessionId,
                IndexFields::OCCURRED_AT => $memory->occurredAt,
                IndexFields::CONTENT_HASH => $memory->contentSignature,
                IndexFields::CHUNK_POSITION => $position,
            ]));
        }

        return $documents;
    }

    /** @param list<IndexedMemory> $unseen */
    private function removeUnseen(IndexGeneration $generation, Store $store, array $unseen): void
    {
        $ids = array_map(static fn (IndexedMemory $memory): int => (int) $memory->id, $unseen);
        $this->entityManager->clear();
        $this->entityManager->wrapInTransaction(function () use ($generation, $store, $ids): void {
            $managedGeneration = $this->lockCurrentGeneration($generation);
            foreach ($ids as $id) {
                $memory = $this->memoryRepository->find($id);
                if (null === $memory) {
                    continue;
                }
                $this->removeChunks($store, $memory->chunkIds);
                $managedGeneration->adjustCounts(-1, -\count($memory->chunkIds));
                $this->entityManager->remove($memory);
            }
            $managedGeneration->touch($this->clock->now());
        });
        $this->projectStatusPublisher->publish((int) $generation->project->id);
    }

    private function finishRound(IndexGeneration $generation): bool
    {
        $snapshot = $this->sourceReader->snapshot($generation->sourcePath);
        if ($snapshot->identity !== $generation->sourceIdentity) {
            return false;
        }
        $revision = $generation->scanRevision;
        if (null === $revision) {
            throw new \LogicException('Cannot finish a closed scan round.');
        }
        if ($snapshot->revision < $revision) {
            return true;
        }

        $more = $snapshot->revision > $revision;
        $projectId = (int) $generation->project->id;
        $generationId = (int) $generation->id;
        $this->entityManager->clear();
        $this->entityManager->wrapInTransaction(function () use ($generation, $revision, $more, $projectId): void {
            $managed = $this->lockCurrentGeneration($generation);
            $now = $this->clock->now();
            $managed->commitRevision($revision, $now);
            if ($more) {
                $managed->markUpdating($now);
            } else {
                $managed->markReady($now);
            }
            if (!$managed->active) {
                $active = $this->generationRepository->findActiveForProject($projectId);
                $active?->markObsolete($now);
                $managed->activate($now);
            }
            $managed->project->markSuccessfulIndex($now);
        });
        $this->removeObsoleteGenerations($projectId, $generationId);
        $this->projectStatusPublisher->publish($projectId);

        return $more;
    }

    private function lockCurrentGeneration(IndexGeneration $expected): IndexGeneration
    {
        $project = $this->entityManager->find(Project::class, (int) $expected->project->id, LockMode::PESSIMISTIC_WRITE);
        $generation = $this->entityManager->find(IndexGeneration::class, (int) $expected->id, LockMode::PESSIMISTIC_WRITE);
        if (null === $project || null === $generation
            || $this->rootBinding($project) !== $expected->rootBinding
            || $project->databasePath !== $expected->sourcePath
            || $this->sourceReader->identityFor($expected->sourcePath) !== $expected->sourceIdentity) {
            throw IndexException::sanitized('Project source rebinding invalidated this index checkpoint.');
        }

        return $generation;
    }

    /** @param list<string> $ids */
    private function removeChunks(Store $store, array $ids): void
    {
        foreach (array_chunk($ids, 100) as $batch) {
            $store->remove($batch);
        }
    }

    private function removeObsoleteGenerations(int $projectId, int $keepGenerationId): void
    {
        try {
            foreach ($this->generationRepository->findObsoleteForProject($projectId, $keepGenerationId) as $obsolete) {
                $this->entityManager->wrapInTransaction(function () use ($projectId, $obsolete): void {
                    $this->storeFactory->removeGeneration($projectId, (int) $obsolete->id);
                    $this->entityManager->remove($obsolete);
                });
            }
        } catch (\Throwable) {
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }
            $this->logger->warning('Obsolete index cleanup deferred until the next indexing tick.', ['projectId' => $projectId]);
        }
    }

    private function rootBinding(Project $project): string
    {
        return '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
    }

    private function persistSafeFailure(int $projectId, IndexException $exception): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }
            $this->entityManager->clear();
            $generation = $this->generationRepository->findLatestForProject($projectId);
            if (null !== $generation) {
                $generation->markError($this->clock->now(), $exception->getMessage());
                $this->entityManager->flush();
                $this->projectStatusPublisher->publish($projectId);
            }
        } catch (\Throwable) {
            $this->logger->error('Unable to persist sanitized index failure state.', ['projectId' => $projectId]);
        }
    }
}
