<?php

declare(strict_types=1);

namespace App\Index\Store;

use App\Index\IndexException;
use App\Index\IndexFields;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Sqlite\Store as SqliteStore;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * Lexical branch: vendor SQLite writes plus FTS SQL that filters before LIMIT.
 *
 * @phpstan-type SharedOptions array{
 *     project_id?: int,
 *     generation_id?: int,
 *     from?: int|null,
 *     until?: int|null,
 *     limit?: int,
 *     maxItems?: int,
 * }
 */
final class SqliteLexicalStoreAdapter implements StoreInterface
{
    public const int DEFAULT_LIMIT = 100;
    private const string TABLE = 'librarian_document';

    public function __construct(
        private readonly SqliteStore $store,
        private readonly \PDO $connection,
    ) {
    }

    public function add(VectorDocumentInterface|array $documents): void
    {
        $documents = \is_array($documents) ? $documents : [$documents];
        $lexical = [];
        foreach ($documents as $document) {
            $lexical[] = new VectorDocument(
                $document->getId(),
                new Vector([1.0]),
                $document->getMetadata(),
                $document->getScore(),
            );
        }
        $this->store->add($lexical);
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
        return TextQuery::class === $queryClass;
    }

    /**
     * @param SharedOptions $options
     *
     * @return iterable<VectorDocumentInterface>
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof TextQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }

        preg_match_all('/[\p{L}\p{N}]+/u', implode(' ', $query->getTexts()), $matches);
        $terms = array_values(array_unique($matches[0]));
        if ([] === $terms) {
            return;
        }

        $limit = (int) ($options['limit'] ?? $options['maxItems'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $ftsQuery = implode(' OR ', array_map(
            static fn (string $term): string => '"'.str_replace('"', '""', $term).'"',
            $terms,
        ));

        $sql = 'SELECT d.id, d.metadata, f.rank'
            .' FROM '.self::TABLE.'_fts f'
            .' INNER JOIN '.self::TABLE.' d ON d.id = f.id'
            .' WHERE '.self::TABLE.'_fts MATCH :query';
        $params = [':query' => $ftsQuery];

        if (isset($options['project_id'])) {
            $sql .= ' AND CAST(json_extract(d.metadata, \'$.'.IndexFields::PROJECT_ID.'\') AS INTEGER) = :project_id';
            $params[':project_id'] = (int) $options['project_id'];
        }
        if (isset($options['generation_id'])) {
            $sql .= ' AND CAST(json_extract(d.metadata, \'$.'.IndexFields::GENERATION_ID.'\') AS INTEGER) = :generation_id';
            $params[':generation_id'] = (int) $options['generation_id'];
        }
        if (isset($options['from']) && null !== $options['from']) {
            $sql .= ' AND CAST(json_extract(d.metadata, \'$.'.IndexFields::OCCURRED_AT.'\') AS INTEGER) >= :from';
            $params[':from'] = (int) $options['from'];
        }
        if (isset($options['until']) && null !== $options['until']) {
            $sql .= ' AND CAST(json_extract(d.metadata, \'$.'.IndexFields::OCCURRED_AT.'\') AS INTEGER) < :until';
            $params[':until'] = (int) $options['until'];
        }

        $sql .= ' ORDER BY f.rank LIMIT '.$limit;

        try {
            $statement = $this->connection->prepare($sql);
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value);
            }
            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Lexical query failed.', $error);
        }

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['id'], $row['metadata'])) {
                throw IndexException::sanitized('Lexical query returned an invalid row.');
            }
            $metadata = json_decode((string) $row['metadata'], true);
            if (!\is_array($metadata)) {
                throw IndexException::sanitized('Lexical query returned invalid metadata.');
            }

            yield new VectorDocument(
                (string) $row['id'],
                new Vector([1.0]),
                new Metadata($metadata),
                isset($row['rank']) && (\is_float($row['rank']) || \is_int($row['rank'])) ? (float) $row['rank'] : null,
            );
        }
    }
}
