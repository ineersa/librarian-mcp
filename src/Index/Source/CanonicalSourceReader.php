<?php

declare(strict_types=1);

namespace App\Index\Source;

use App\Index\IndexException;
use App\Project\OmSourceReader;
use App\Project\ProjectSourceException;

/**
 * Reads retained OM observations/reflections in bounded keyset batches.
 *
 * Opens source SQLite through OmSourceReader's shared readonly opener. Never
 * consumes/deletes om_source_change rows: that journal is not an independent
 * durable consumer feed under the current Hatfield semantic indexer.
 */
final class CanonicalSourceReader
{
    private const array OBSERVATION_RELEVANCE = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly OmSourceReader $omSourceReader,
    ) {
    }

    public function snapshot(string $databasePath): SourceSnapshot
    {
        try {
            $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);
        } catch (ProjectSourceException $exception) {
            throw IndexException::sanitized($exception->getMessage(), $exception);
        }

        try {
            $pdo->beginTransaction();
            try {
                $this->omSourceReader->validateSchema($pdo);
                $revision = $this->fetchRevision($pdo);
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $this->wrapReadFailure($exception);
            }

            return new SourceSnapshot(
                path: $databasePath,
                identity: $this->identityFor($databasePath),
                revision: $revision,
            );
        } finally {
            $pdo = null;
        }
    }

    /**
     * @return list<CanonicalMemory>
     */
    public function fetchBatch(string $databasePath, ?string $cursor, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Source batch limit must be positive.');
        }

        try {
            $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);
        } catch (ProjectSourceException $exception) {
            throw IndexException::sanitized($exception->getMessage(), $exception);
        }

        try {
            $pdo->beginTransaction();
            try {
                $rows = $this->queryBatch($pdo, $cursor, $limit);
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $this->wrapReadFailure($exception);
            }

            return $rows;
        } finally {
            $pdo = null;
        }
    }

    /**
     * Load retained memories by exact source ids for one kind.
     *
     * Bound at 50 ids so callers can hydrate ranked parents without scanning the
     * full source table. Missing ids are omitted. Results reuse hydrate() so the
     * contentSignature matches indexing.
     *
     * @param list<string> $ids
     *
     * @return list<CanonicalMemory>
     */
    public function fetchByIds(string $databasePath, MemoryKind $kind, array $ids): array
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
            $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);
        } catch (ProjectSourceException $exception) {
            throw IndexException::sanitized($exception->getMessage(), $exception);
        }

        try {
            $pdo->beginTransaction();
            try {
                $this->omSourceReader->validateSchema($pdo);
                $rows = $this->queryByIds($pdo, $kind, $normalized);
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $this->wrapReadFailure($exception);
            }

            return $rows;
        } finally {
            $pdo = null;
        }
    }

    /**
     * Cheap stable filesystem identity (path + device + inode).
     *
     * Does not open SQLite or count source rows. Use snapshot() to read the revision.
     */
    public function identityFor(string $databasePath): string
    {
        clearstatcache(true, $databasePath);
        $resolved = realpath($databasePath);
        if (false === $resolved) {
            throw IndexException::sanitized('Unable to resolve observational memory database path.');
        }

        $stat = @stat($resolved);
        if (!\is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw IndexException::sanitized('Unable to read observational memory filesystem identity.');
        }

        return hash('sha256', json_encode([
            $resolved,
            $stat['dev'],
            $stat['ino'],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<CanonicalMemory>
     */
    private function queryBatch(\PDO $pdo, ?string $cursor, int $limit): array
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
            foreach ($this->queryKind($pdo, $kind, $afterId, $remaining) as $memory) {
                $memories[] = $memory;
            }
        }

        return $memories;
    }

    /**
     * @return list<CanonicalMemory>
     */
    private function queryKind(\PDO $pdo, MemoryKind $kind, ?string $afterId, int $limit): array
    {
        $idColumn = $kind->idColumn();
        $dateColumn = $kind->dateColumn();
        $relevanceSelect = MemoryKind::Observation === $kind ? 'relevance' : 'NULL AS relevance';
        $where = null === $afterId ? '' : \sprintf('WHERE %s > :after_id', $idColumn);

        // Per-table PK keyset. Avoid sorting a full UNION for every page.
        $sql = \sprintf(
            'SELECT %s AS source_id, run_id AS session_id, content, %s AS occurred_raw, %s
             FROM %s
             %s
             ORDER BY %s ASC
             LIMIT :limit',
            $idColumn,
            $dateColumn,
            $relevanceSelect,
            $kind->table(),
            $where,
            $idColumn,
        );

        $statement = $pdo->prepare($sql);
        if (null !== $afterId) {
            $statement->bindValue(':after_id', $afterId);
        }
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $memories = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memories[] = $this->hydrate($kind, $row);
        }

        return $memories;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<CanonicalMemory>
     */
    private function queryByIds(\PDO $pdo, MemoryKind $kind, array $ids): array
    {
        $idColumn = $kind->idColumn();
        $dateColumn = $kind->dateColumn();
        $relevanceSelect = MemoryKind::Observation === $kind ? 'relevance' : 'NULL AS relevance';
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = 'id_'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $id;
        }

        $sql = \sprintf(
            'SELECT %s AS source_id, run_id AS session_id, content, %s AS occurred_raw, %s
             FROM %s
             WHERE %s IN (%s)
             ORDER BY %s ASC',
            $idColumn,
            $dateColumn,
            $relevanceSelect,
            $kind->table(),
            $idColumn,
            implode(', ', $placeholders),
            $idColumn,
        );

        $statement = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(':'.$name, $value);
        }
        $statement->execute();

        $memories = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
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

    private function fetchRevision(\PDO $pdo): int
    {
        $value = $pdo->query('SELECT revision FROM om_source_revision WHERE id = 1')->fetchColumn();
        if (false === $value || null === $value) {
            throw IndexException::sanitized('Observational memory source revision is missing.');
        }

        return (int) $value;
    }

    private function wrapReadFailure(\Throwable $exception): IndexException
    {
        if ($exception instanceof IndexException) {
            return $exception;
        }

        if ($exception instanceof ProjectSourceException) {
            return IndexException::sanitized($exception->getMessage(), $exception);
        }

        return IndexException::sanitized('Unable to read observational memory source batch.', $exception);
    }
}
