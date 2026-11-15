<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Project;
use App\Index\IndexException;
use App\Index\Search\HybridSearch;
use App\Index\Search\MemoryDateRange;
use App\Index\Source\CanonicalSourceReader;
use App\Index\Source\MemoryKind;
use App\Memory\MemoryErrorCode;
use App\Repository\IndexedMemoryRepository;
use App\Repository\IndexGenerationRepository;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Thin project-scoped retrieval boundary; never treats a stored chunk as canonical evidence. */
final readonly class MemorySearch
{
    private const int MAX_QUERY_BYTES = 16_384;
    private const int DISPLAY_ID_LENGTH = 12;

    public function __construct(
        private HybridSearch $hybridSearch,
        private IndexGenerationRepository $generations,
        private IndexedMemoryRepository $manifests,
        private CanonicalSourceReader $sourceReader,
        private ValidatorInterface $validator,
        private DenormalizerInterface $denormalizer,
    ) {
    }

    /** @return array<string, mixed> */
    public function search(Project $project, string $query, ?string $after, ?string $before, int $limit): array
    {
        $query = trim($query);
        if ('' === $query || \strlen($query) > self::MAX_QUERY_BYTES) {
            throw new MemoryToolException(MemoryErrorCode::InvalidQuery, 'query must be nonblank and at most 16384 UTF-8 bytes.');
        }
        if ($limit < 1 || $limit > MemoryTools::MAX_LIMIT) {
            throw new MemoryToolException(MemoryErrorCode::InvalidLimit, 'limit must be between 1 and 50.');
        }
        try {
            $dates = new MemoryDateRange(
                $this->dateBound($after, MemoryErrorCode::InvalidAfter),
                $this->dateBound($before, MemoryErrorCode::InvalidBefore, upperBound: true),
            );
        } catch (\InvalidArgumentException $error) {
            throw new MemoryToolException(MemoryErrorCode::InvalidDateRange, $error->getMessage());
        }

        $projectId = $project->id;
        $generation = null === $projectId ? null : $this->generations->findActiveForProject($projectId);
        if (null === $generation || null === $generation->id || !$generation->state->isUsable() || null === $generation->committedRevision) {
            throw new MemoryToolException(MemoryErrorCode::IndexUnavailable, 'No committed searchable index is active for this project yet.');
        }
        if (null === $project->databasePath) {
            throw new MemoryToolException(MemoryErrorCode::SourceUnavailable, 'The registered project has no discovered OM source.');
        }
        try {
            $snapshot = $this->sourceReader->snapshot($project->databasePath);
        } catch (IndexException) {
            throw new MemoryToolException(MemoryErrorCode::SourceUnavailable, 'The registered OM source cannot currently be read.');
        }
        if ($snapshot->revision < $generation->committedRevision) {
            throw new MemoryToolException(MemoryErrorCode::IndexUnavailable, 'The committed index is ahead of the current source revision; wait for reconciliation.');
        }

        try {
            $retrieval = $this->hybridSearch->search($projectId, $generation->id, $query, $limit, $dates);
        } catch (IndexException $error) {
            $code = match ($error->getCode()) {
                IndexException::QUERY_EMBEDDING_FAILED => MemoryErrorCode::EmbeddingFailed,
                IndexException::QUERY_RETRIEVAL_FAILED => MemoryErrorCode::RetrievalFailed,
                IndexException::QUERY_TIMEOUT => MemoryErrorCode::Timeout,
                default => MemoryErrorCode::IndexUnavailable,
            };
            throw new MemoryToolException($code, $error->getMessage());
        }

        $parentIds = array_values(array_unique(array_column($retrieval->results, 'source_id')));
        $committed = [];
        foreach ($this->manifests->findParents($generation->id, $parentIds) as $manifest) {
            $committed[$manifest->kind."\0".$manifest->sourceId] = $manifest->contentSignature;
        }
        $canonical = [];
        try {
            foreach (MemoryKind::cases() as $kind) {
                $ids = [];
                foreach ($retrieval->results as $hit) {
                    if ($hit['kind'] === $kind->value) {
                        $ids[] = $hit['source_id'];
                    }
                }
                foreach ($this->sourceReader->fetchByIds($project->databasePath, $kind, $ids) as $memory) {
                    $canonical[$memory->kind->value."\0".$memory->sourceId] = $memory;
                }
            }
            $latest = $this->sourceReader->snapshot($project->databasePath);
        } catch (IndexException) {
            throw new MemoryToolException(MemoryErrorCode::SourceUnavailable, 'Canonical search results cannot currently be read.');
        }
        if ($latest->identity !== $snapshot->identity) {
            throw new MemoryToolException(MemoryErrorCode::SourceUnavailable, 'The OM source changed binding during retrieval; retry the search.');
        }

        $results = [];
        $stale = false;
        foreach ($retrieval->results as $hit) {
            $key = $hit['kind']."\0".$hit['source_id'];
            $memory = $canonical[$key] ?? null;
            if (null === $memory || ($committed[$key] ?? null) !== $hit['content_hash'] || $memory->contentSignature !== $hit['content_hash']) {
                $stale = true;
                continue;
            }
            $result = [
                'kind' => $memory->kind->value,
                'id' => $memory->sourceId,
                'display_id' => substr($memory->sourceId, 0, self::DISPLAY_ID_LENGTH),
                'session_id' => $memory->sessionId,
                'timestamp' => $memory->timestamp,
                'content' => $memory->content,
            ];
            if (MemoryKind::Observation === $memory->kind) {
                $result['importance'] = $memory->relevance;
            }
            $results[] = $result;
        }

        return [
            'ok' => true,
            'query' => $query,
            'limit' => $limit,
            'count' => \count($results),
            'results' => $results,
            'truncated' => $retrieval->truncated,
            'candidate_truncated' => $retrieval->candidateTruncated,
            'result_truncated' => $retrieval->resultTruncated,
            'partial' => $stale || $generation->state->isInProgress() || $latest->revision !== $generation->committedRevision,
            'project' => MemoryContextResolver::provenance($project),
            'index' => [
                'generation_id' => $generation->id,
                'state' => $generation->state->value,
                'committed_revision' => $generation->committedRevision,
                'source_revision' => $latest->revision,
            ],
        ];
    }

    private function dateBound(?string $value, MemoryErrorCode $errorCode, bool $upperBound = false): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        // The native MCP schema already validates shape; Symfony validates real dates.
        $dayPrecision = !str_contains($value, ' ');
        $format = $dayPrecision ? '!Y-m-d' : '!Y-m-d H:i';
        if (\count($this->validator->validate($value, new Assert\DateTime(format: $format))) > 0) {
            throw new MemoryToolException($errorCode, 'Date bound must be a real calendar date (YYYY-MM-DD or YYYY-MM-DD HH:MM).');
        }

        $date = $this->denormalizer->denormalize($value, \DateTimeImmutable::class, context: [
            DateTimeNormalizer::FORMAT_KEY => $format,
            DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
        ]);

        return $upperBound ? $date->modify($dayPrecision ? '+1 day' : '+1 minute') : $date;
    }
}
