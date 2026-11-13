<?php

declare(strict_types=1);

namespace App\Index\Store;

use App\Index\IndexFields;
use Symfony\AI\Store\Bridge\ChromaDb\Store as ChromaStore;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * Maps shared hybrid options onto Chroma vector query options.
 *
 * @phpstan-type SharedOptions array{
 *     project_id?: int,
 *     generation_id?: int,
 *     from?: int|null,
 *     until?: int|null,
 *     limit?: int,
 *     maxItems?: int,
 *     where?: array<string, mixed>,
 *     whereDocument?: array<string, mixed>,
 *     include?: list<string>,
 * }
 */
final readonly class ChromaVectorStoreAdapter implements StoreInterface
{
    public const int DEFAULT_LIMIT = 100;

    public function __construct(
        private ChromaStore $store,
    ) {
    }

    public function add(VectorDocumentInterface|array $documents): void
    {
        $this->store->add($documents);
    }

    public function remove(array|string $ids, array $options = []): void
    {
        $this->store->remove($ids, $options);
    }

    public function clear(array $options = []): void
    {
        $this->store->clear($options);
    }

    public function count(): int
    {
        return $this->store->count();
    }

    public function supports(string $queryClass): bool
    {
        return VectorQuery::class === $queryClass;
    }

    /**
     * @param SharedOptions $options
     *
     * @return iterable<VectorDocumentInterface>
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        return $this->store->query($query, $this->chromaOptions($options));
    }

    /**
     * @param SharedOptions $options
     *
     * @return array{
     *     where?: array<string, mixed>,
     *     whereDocument?: array<string, mixed>,
     *     include: list<string>,
     *     limit: int
     * }
     */
    private function chromaOptions(array $options): array
    {
        $where = $options['where'] ?? $this->buildWhere($options);
        $limit = (int) ($options['limit'] ?? $options['maxItems'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $mapped = [
            'include' => $options['include'] ?? ['documents', 'embeddings', 'metadatas', 'distances'],
            'limit' => $limit,
        ];
        if (null !== $where && [] !== $where) {
            $mapped['where'] = $where;
        }
        if (isset($options['whereDocument']) && \is_array($options['whereDocument'])) {
            $mapped['whereDocument'] = $options['whereDocument'];
        }

        return $mapped;
    }

    /**
     * @param SharedOptions $options
     *
     * @return array<string, mixed>|null
     */
    private function buildWhere(array $options): ?array
    {
        $conditions = [];
        if (isset($options['project_id'])) {
            $conditions[] = [IndexFields::PROJECT_ID => ['$eq' => (int) $options['project_id']]];
        }
        if (isset($options['generation_id'])) {
            $conditions[] = [IndexFields::GENERATION_ID => ['$eq' => (int) $options['generation_id']]];
        }
        if (isset($options['from']) && null !== $options['from']) {
            $conditions[] = [IndexFields::OCCURRED_AT => ['$gte' => (int) $options['from']]];
        }
        if (isset($options['until']) && null !== $options['until']) {
            $conditions[] = [IndexFields::OCCURRED_AT => ['$lt' => (int) $options['until']]];
        }

        return match (\count($conditions)) {
            0 => null,
            1 => $conditions[0],
            default => ['$and' => $conditions],
        };
    }
}
