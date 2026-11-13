<?php

declare(strict_types=1);

namespace App\Index\Search;

use App\Index\IndexException;
use App\Index\IndexFields;
use App\Index\IndexSettings;
use App\Index\Model\LocalBgeReranker;
use App\Index\Model\ValidatingVectorizer;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Store\IndexStoreFactory;
use App\Repository\IndexedMemoryRepository;
use App\Repository\IndexGenerationRepository;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\HybridQuery;

/**
 * Vector100 + lexical100 -> RRF k60 -> fused<=200 -> BGE -> parent collapse.
 */
final readonly class HybridSearch
{
    public const int VECTOR_LIMIT = 100;
    public const int TEXT_LIMIT = 100;
    public const int FUSED_LIMIT = 200;

    public function __construct(
        private IndexStoreFactory $storeFactory,
        private ValidatingVectorizer $vectorizer,
        private LocalBgeReranker $reranker,
        private IndexGenerationRepository $generationRepository,
        private IndexedMemoryRepository $memoryRepository,
        private CanonicalSourceReader $sourceReader,
        private IndexSettings $settings,
    ) {
    }

    public function search(
        int $projectId,
        int $generationId,
        string $question,
        ?int $from = null,
        ?int $until = null,
        int $limit = 10,
    ): SearchResult {
        if ($projectId < 1 || $generationId < 1) {
            throw new \InvalidArgumentException('Project and generation IDs must be positive integers.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('Result limit must be positive.');
        }
        if ('' === trim($question)) {
            throw new \InvalidArgumentException('Search question must not be empty.');
        }

        $generation = $this->generationRepository->find($generationId);
        if (null === $generation || !$generation->active || null === $generation->committedRevision
            || $generation->project->id !== $projectId
            || $generation->signature !== $this->settings->signature()) {
            throw IndexException::sanitized('No compatible committed index is active for this project.');
        }
        $project = $generation->project;
        $root = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
        if ($generation->rootBinding !== $root || $generation->sourcePath !== $project->databasePath
            || $this->sourceReader->identityFor($generation->sourcePath) !== $generation->sourceIdentity) {
            throw IndexException::sanitized('The active index belongs to an earlier source binding. Wait for indexing to complete.');
        }

        $stores = $this->storeFactory->forGeneration($projectId, $generationId, initialize: false);
        $vector = $this->vectorizer->embedQuery($question);
        $options = [
            'project_id' => $projectId,
            'generation_id' => $generationId,
            'from' => $from,
            'until' => $until,
            'limit' => self::VECTOR_LIMIT,
            'maxItems' => self::TEXT_LIMIT,
        ];

        try {
            $fused = iterator_to_array(
                $stores->combined->query(new HybridQuery($vector, $question), $options),
                false,
            );
        } catch (IndexException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Hybrid retrieval failed.', $error);
        }

        $candidateLimitReached = \count($fused) > self::FUSED_LIMIT;
        if ($candidateLimitReached) {
            $fused = \array_slice($fused, 0, self::FUSED_LIMIT);
        }

        // A remote write may succeed before the relational acknowledgement. Only
        // committed parent signatures/chunk IDs may enter reranking or results.
        $candidates = [];
        foreach ($fused as $document) {
            $metadata = $document->getMetadata();
            $kind = $metadata[IndexFields::KIND] ?? null;
            $sourceId = $metadata[IndexFields::SOURCE_ID] ?? null;
            $signature = $metadata[IndexFields::CONTENT_HASH] ?? null;
            if (!\is_string($kind) || !\is_string($sourceId) || !\is_string($signature)) {
                throw IndexException::sanitized('Indexed candidate is missing committed provenance.');
            }
            $candidates[] = ['kind' => $kind, 'source_id' => $sourceId, 'signature' => $signature, 'chunk_id' => (string) $document->getId()];
        }
        $committedIds = array_fill_keys($this->memoryRepository->findCommittedChunkIds($generationId, $candidates), true);
        $committed = [];
        foreach ($fused as $document) {
            if (isset($committedIds[(string) $document->getId()])) {
                $committed[] = $document;
            }
        }
        $candidateLimitReached = $candidateLimitReached || \count($committed) !== \count($fused);
        $fused = $committed;

        $snippets = [];
        foreach ($fused as $document) {
            $text = $document->getMetadata()->getText();
            if (null === $text || '' === $text) {
                throw IndexException::sanitized('Fused candidate is missing document text.');
            }
            $snippets[] = $text;
        }

        $ranked = [] === $snippets ? [] : $this->reranker->rankWithScores($question, $snippets);
        $parents = [];
        foreach ($ranked as $entry) {
            $offset = $entry['offset'];
            if (!isset($fused[$offset])) {
                throw IndexException::sanitized('Reranker returned an unknown candidate offset.');
            }
            $hit = $this->toHit($fused[$offset], $projectId, $generationId, $entry['score']);
            $key = $hit['kind']."\0".$hit['source_id'];
            if (isset($parents[$key])) {
                continue;
            }
            $parents[$key] = $hit;
            if (\count($parents) >= $limit) {
                break;
            }
        }

        $truncated = $stores->wasTruncated()
            || $candidateLimitReached
            || \count($fused) >= self::FUSED_LIMIT;

        return new SearchResult(array_values($parents), $truncated);
    }

    /**
     * @return array{
     *     project_id: int,
     *     generation_id: int,
     *     kind: string,
     *     source_id: string,
     *     session_id: string,
     *     occurred_at: int,
     *     content_hash: string,
     *     chunk_position: int,
     *     snippet: string,
     *     rerank_score: float
     * }
     */
    private function toHit(VectorDocumentInterface $document, int $projectId, int $generationId, float $rerankScore): array
    {
        $metadata = $document->getMetadata();
        $kind = $metadata[IndexFields::KIND] ?? null;
        $sourceId = $metadata[IndexFields::SOURCE_ID] ?? null;
        $sessionId = $metadata[IndexFields::SESSION_ID] ?? null;
        $occurredAt = $metadata[IndexFields::OCCURRED_AT] ?? null;
        $contentHash = $metadata[IndexFields::CONTENT_HASH] ?? null;
        $chunkPosition = $metadata[IndexFields::CHUNK_POSITION] ?? null;
        $snippet = $metadata->getText();

        if (!\is_string($kind) || '' === $kind
            || !\is_string($sourceId) || '' === $sourceId
            || !\is_string($sessionId) || '' === $sessionId
            || (!\is_int($occurredAt) && !\is_float($occurredAt))
            || !\is_string($contentHash) || '' === $contentHash
            || (!\is_int($chunkPosition) && !\is_float($chunkPosition))
            || null === $snippet || '' === $snippet
        ) {
            throw IndexException::sanitized('Indexed candidate is missing required provenance metadata.');
        }

        $metaProject = $metadata[IndexFields::PROJECT_ID] ?? $projectId;
        $metaGeneration = $metadata[IndexFields::GENERATION_ID] ?? $generationId;
        if ((int) $metaProject !== $projectId || (int) $metaGeneration !== $generationId) {
            throw IndexException::sanitized('Indexed candidate escaped the requested project/generation filter.');
        }

        return [
            'project_id' => $projectId,
            'generation_id' => $generationId,
            'kind' => $kind,
            'source_id' => $sourceId,
            'session_id' => $sessionId,
            'occurred_at' => (int) $occurredAt,
            'content_hash' => $contentHash,
            'chunk_position' => (int) $chunkPosition,
            'snippet' => $snippet,
            'rerank_score' => $rerankScore,
        ];
    }
}
