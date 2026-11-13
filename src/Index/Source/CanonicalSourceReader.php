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
    private const int DATABASE_HEADER_BYTES = 100;
    private const int WAL_HEADER_BYTES = 32;

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
                $revision = $this->fetchRevision($pdo);
                $observationCount = (int) $pdo->query('SELECT COUNT(*) FROM om_observation')->fetchColumn();
                $reflectionCount = (int) $pdo->query('SELECT COUNT(*) FROM om_reflection')->fetchColumn();
                $changeHintCount = (int) $pdo->query('SELECT COUNT(*) FROM om_source_change')->fetchColumn();
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
                witness: $this->witnessFor($databasePath, $revision),
                revision: $revision,
                observationCount: $observationCount,
                reflectionCount: $reflectionCount,
                changeHintCount: $changeHintCount,
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

        // Per-table PK keyset. Avoid sorting a full UNION for every page.
        $sql = \sprintf(
            'SELECT %s AS source_id, run_id AS session_id, content, %s AS occurred_raw, %s
             FROM %s
             WHERE (:after_id IS NULL OR %s > :after_id)
             ORDER BY %s ASC
             LIMIT :limit',
            $idColumn,
            $dateColumn,
            $relevanceSelect,
            $kind->table(),
            $idColumn,
            $idColumn,
        );

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':after_id', $afterId);
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
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

    private function fetchRevision(\PDO $pdo): ?int
    {
        $value = $pdo->query('SELECT revision FROM om_source_revision WHERE id = 1')->fetchColumn();
        if (false === $value || null === $value) {
            return null;
        }

        return (int) $value;
    }

    private function identityFor(string $databasePath): string
    {
        clearstatcache(true, $databasePath);
        $stat = @stat($databasePath);
        $dev = \is_array($stat) ? ($stat['dev'] ?? null) : null;
        $ino = \is_array($stat) ? ($stat['ino'] ?? null) : null;
        $resolved = realpath($databasePath);
        if (false === $resolved) {
            $resolved = $databasePath;
        }

        return hash('sha256', json_encode([
            $resolved,
            $dev,
            $ino,
        ], \JSON_THROW_ON_ERROR));
    }

    private function witnessFor(string $databasePath, ?int $revision): string
    {
        clearstatcache(true, $databasePath);
        $stat = @stat($databasePath);
        $size = \is_array($stat) ? ($stat['size'] ?? null) : null;
        $mtime = \is_array($stat) ? ($stat['mtime'] ?? null) : null;
        $ctime = \is_array($stat) ? ($stat['ctime'] ?? null) : null;
        $databaseHeader = $this->boundedHeaderHash($databasePath, self::DATABASE_HEADER_BYTES);

        $walPath = $databasePath.'-wal';
        $walHeader = null;
        $walSize = null;
        $walMtime = null;
        $walCtime = null;
        if (is_file($walPath)) {
            clearstatcache(true, $walPath);
            $walStat = @stat($walPath);
            $walSize = \is_array($walStat) ? ($walStat['size'] ?? null) : null;
            $walMtime = \is_array($walStat) ? ($walStat['mtime'] ?? null) : null;
            $walCtime = \is_array($walStat) ? ($walStat['ctime'] ?? null) : null;
            $walHeader = $this->boundedHeaderHash($walPath, self::WAL_HEADER_BYTES);
        }

        // Cheap mutable witness only. Same-revision restores that also preserve
        // every bounded witness field remain invisible without full verification.
        return hash('sha256', json_encode([
            $size,
            $mtime,
            $ctime,
            $databaseHeader,
            $walSize,
            $walMtime,
            $walCtime,
            $walHeader,
            $revision,
        ], \JSON_THROW_ON_ERROR));
    }

    private function boundedHeaderHash(string $path, int $bytes): ?string
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $payload = fread($handle, $bytes);
            if (!\is_string($payload) || '' === $payload) {
                return null;
            }

            return hash('sha256', $payload);
        } finally {
            fclose($handle);
        }
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
