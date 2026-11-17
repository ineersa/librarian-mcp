<?php

declare(strict_types=1);

namespace App\Index\Source;

use App\Index\IndexException;
use App\ObservationalMemory\Storage\MemoryRevision;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads a project's OM observations/reflections from Librarian-owned memory tables
 * in bounded keyset batches.
 *
 * The source revision is the project's memory_revision counter, bumped in the same
 * transaction as every memory write (port of OM om_source_revision).
 */
final class CanonicalSourceReader
{
    /** Source binding stored on index generations; replaces the former om.sqlite path. */
    public const string SOURCE_PATH = 'librarian:om-postgres-v1';

    private const array OBSERVATION_RELEVANCE = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function snapshot(int $projectId): SourceSnapshot
    {
        try {
            $revision = MemoryRevision::current($this->connection, $projectId);
        } catch (\Throwable $exception) {
            throw $this->wrapReadFailure($exception);
        }

        return new SourceSnapshot(
            path: self::SOURCE_PATH,
            identity: $this->identityFor($projectId),
            revision: $revision,
        );
    }

    /**
     * @return list<CanonicalMemory>
     */
    public function fetchBatch(int $projectId, ?string $cursor, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Source batch limit must be positive.');
        }

        try {
            return $this->queryBatch($projectId, $cursor, $limit);
        } catch (\Throwable $exception) {
            throw $this->wrapReadFailure($exception);
        }
    }

    /**
     * Load memories by exact source ids for one kind.
     *
     * Bound at 50 ids so callers can hydrate ranked parents without scanning the
     * full source table. Missing ids are omitted. Results reuse hydrate() so the
     * contentSignature matches indexing.
     *
     * @param list<string> $ids
     *
     * @return list<CanonicalMemory>
     */
    public function fetchByIds(int $projectId, MemoryKind $kind, array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!\is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ('' === $id) {
                continue;
            }
            $normalized[$id] = $id;
        }
        $normalized = array_values($normalized);
        if ([] === $normalized) {
            return [];
        }
        if (\count($normalized) > 50) {
            throw new \InvalidArgumentException('Source id lookup is limited to 50 ids per kind.');
        }

        try {
            return $this->hydrateRows($kind, $this->connection->fetchAllAssociative(
                $this->selectSql($kind).' AND m.'.$kind->idColumn().' IN (:ids) ORDER BY m.'.$kind->idColumn().' ASC',
                ['project' => $projectId, 'ids' => $normalized],
                ['ids' => ArrayParameterType::STRING],
            ));
        } catch (\Throwable $exception) {
            throw $this->wrapReadFailure($exception);
        }
    }

    /** Stable binding of a project's memory source; no I/O. */
    public function identityFor(int $projectId): string
    {
        return hash('sha256', json_encode([self::SOURCE_PATH, $projectId], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<CanonicalMemory>
     */
    private function queryBatch(int $projectId, ?string $cursor, int $limit): array
    {
        [$cursorKind, $cursorId] = $this->parseCursor($cursor);
        $memories = [];
        $started = false;

        foreach (MemoryKind::keysetOrder() as $kind) {
            if (null !== $cursorKind && !$started) {
                if ($kind->value < $cursorKind->value) {
                    continue;
                }
                $started = true;
            } else {
                $started = true;
            }

            $remaining = $limit - \count($memories);
            if ($remaining < 1) {
                break;
            }

            $afterId = (null !== $cursorKind && $kind === $cursorKind) ? $cursorId : null;
            foreach ($this->queryKind($projectId, $kind, $afterId, $remaining) as $memory) {
                $memories[] = $memory;
            }
        }

        return $memories;
    }

    /**
     * @return list<CanonicalMemory>
     */
    private function queryKind(int $projectId, MemoryKind $kind, ?string $afterId, int $limit): array
    {
        $idColumn = 'm.'.$kind->idColumn();
        $parameters = ['project' => $projectId, 'limit' => $limit];
        $where = '';
        if (null !== $afterId) {
            $where = ' AND '.$idColumn.' > :after_id';
            $parameters['after_id'] = $afterId;
        }

        // Per-table keyset. Avoid sorting a full UNION for every page.
        return $this->hydrateRows($kind, $this->connection->fetchAllAssociative(
            $this->selectSql($kind).$where.' ORDER BY '.$idColumn.' ASC LIMIT :limit',
            $parameters,
        ));
    }

    private function selectSql(MemoryKind $kind): string
    {
        return \sprintf(
            'SELECT m.%s AS source_id, s.session_id AS session_id, m.content, m.%s AS occurred_raw, %s
             FROM %s m
             INNER JOIN om_sessions s ON s.id = m.memory_session_id
             WHERE s.project_id = :project',
            $kind->idColumn(),
            $kind->dateColumn(),
            MemoryKind::Observation === $kind ? 'm.relevance' : 'NULL AS relevance',
            $kind->table(),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<CanonicalMemory>
     */
    private function hydrateRows(MemoryKind $kind, array $rows): array
    {
        $memories = [];
        foreach ($rows as $row) {
            $memories[] = $this->hydrate($kind, $row);
        }

        return $memories;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(MemoryKind $kind, array $row): CanonicalMemory
    {
        $sourceId = $row['source_id'] ?? null;
        $sessionId = $row['session_id'] ?? null;
        $content = $row['content'] ?? null;
        $occurredRaw = $row['occurred_raw'] ?? null;
        $relevance = $row['relevance'] ?? null;

        if (!\is_string($sourceId) || '' === $sourceId) {
            throw IndexException::sanitized('Canonical memory identity is missing.');
        }
        if (!\is_string($sessionId) || '' === $sessionId) {
            throw IndexException::sanitized('Canonical memory session identity is missing.');
        }
        if (!\is_string($content)) {
            throw IndexException::sanitized('Canonical memory content is missing.');
        }

        $occurredAt = $this->normalizeOccurredAt($occurredRaw);
        $relevanceValue = null;
        if (MemoryKind::Observation === $kind) {
            if (!\is_string($relevance) || !\in_array($relevance, self::OBSERVATION_RELEVANCE, true)) {
                throw IndexException::sanitized('Canonical observation relevance is missing or invalid.');
            }
            $relevanceValue = $relevance;
        } elseif (null !== $relevance) {
            throw IndexException::sanitized('Canonical reflection relevance must be null.');
        }

        $signature = hash('sha256', json_encode([
            $kind->value,
            $sourceId,
            $sessionId,
            $content,
            $occurredAt,
            $relevanceValue,
        ], \JSON_THROW_ON_ERROR));

        return new CanonicalMemory(
            kind: $kind,
            sourceId: $sourceId,
            sessionId: $sessionId,
            content: $content,
            occurredAt: $occurredAt,
            relevance: $relevanceValue,
            contentSignature: $signature,
            timestamp: \is_string($occurredRaw) ? $occurredRaw : null,
        );
    }

    private function normalizeOccurredAt(mixed $raw): int
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            throw IndexException::sanitized('Canonical memory date is missing or invalid.');
        }

        // OM stores local-looking timestamps without timezone; treat as UTC.
        $normalized = str_replace('T', ' ', trim($raw));
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
            $normalized .= ':00';
        }

        try {
            $date = new \DateTimeImmutable($normalized, new \DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw IndexException::sanitized('Canonical memory date is missing or invalid.', $exception);
        }

        return $date->getTimestamp();
    }

    /**
     * @return array{0: ?MemoryKind, 1: ?string}
     */
    private function parseCursor(?string $cursor): array
    {
        if (null === $cursor || '' === trim($cursor)) {
            return [null, null];
        }

        $parts = explode('|', $cursor, 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException('Source cursor must be kind|source_id.');
        }

        $kind = MemoryKind::tryFrom($parts[0]);
        if (null === $kind) {
            throw new \InvalidArgumentException('Source cursor kind is unsupported.');
        }

        return [$kind, $parts[1]];
    }

    private function wrapReadFailure(\Throwable $exception): IndexException
    {
        if ($exception instanceof IndexException) {
            return $exception;
        }

        return IndexException::sanitized('Unable to read observational memory source batch.', $exception);
    }
}
