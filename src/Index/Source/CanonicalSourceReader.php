<?php

declare(strict_types=1);

namespace App\Index\Source;

use App\Index\IndexFields;
use App\Project\OmSourceReader;
use App\Project\ProjectSourceException;
use App\Project\ProjectSourceIssueCode;

/**
 * Reads retained OM observations/reflections in bounded keyset batches.
 *
 * Opens source SQLite through OmSourceReader's shared readonly opener. Never
 * consumes/deletes om_source_change rows: that journal is not an independent
 * durable consumer feed under the current Hatfield semantic indexer.
 */
final class CanonicalSourceReader
{
    public function __construct(
        private readonly OmSourceReader $omSourceReader,
    ) {
    }

    public function snapshot(string $databasePath): SourceSnapshot
    {
        $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);

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
                identity: $this->identityFor($databasePath, $revision),
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

        $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);

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

        $sql = <<<'SQL'
            SELECT kind, source_id, session_id, content, occurred_at, relevance
            FROM (
                SELECT
                    'observation' AS kind,
                    observation_id AS source_id,
                    run_id AS session_id,
                    content AS content,
                    CAST(strftime('%s', timestamp) AS INTEGER) AS occurred_at,
                    relevance AS relevance
                FROM om_observation
                UNION ALL
                SELECT
                    'reflection' AS kind,
                    reflection_id AS source_id,
                    run_id AS session_id,
                    content AS content,
                    CAST(strftime('%s', created_at) AS INTEGER) AS occurred_at,
                    NULL AS relevance
                FROM om_reflection
            ) AS memories
            WHERE (:cursor_kind IS NULL)
               OR (kind > :cursor_kind)
               OR (kind = :cursor_kind AND source_id > :cursor_id)
            ORDER BY kind ASC, source_id ASC
            LIMIT :limit
            SQL;

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':cursor_kind', $cursorKind);
        $statement->bindValue(':cursor_id', $cursorId);
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $memories = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memories[] = $this->hydrate($row);
        }

        return $memories;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CanonicalMemory
    {
        $kind = $row['kind'] ?? null;
        $sourceId = $row['source_id'] ?? null;
        $sessionId = $row['session_id'] ?? null;
        $content = $row['content'] ?? null;
        $occurredAt = $row['occurred_at'] ?? null;
        $relevance = $row['relevance'] ?? null;

        if (!\is_string($kind) || (IndexFields::KIND_OBSERVATION !== $kind && IndexFields::KIND_REFLECTION !== $kind)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory kind is invalid.');
        }
        if (!\is_string($sourceId) || '' === $sourceId) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory identity is missing.');
        }
        if (!\is_string($sessionId) || '' === $sessionId) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory session identity is missing.');
        }
        if (!\is_string($content)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory content is missing.');
        }
        if (!\is_int($occurredAt) && !(is_numeric($occurredAt) && (string) (int) $occurredAt === (string) $occurredAt)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory date is missing or invalid.');
        }
        if (null !== $relevance && !\is_int($relevance) && !\is_float($relevance)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Canonical memory relevance is invalid.');
        }

        $occurred = (int) $occurredAt;
        $relevanceValue = null === $relevance ? null : (float) $relevance;
        $signature = hash('sha256', json_encode([
            $kind,
            $sourceId,
            $sessionId,
            $content,
            $occurred,
            $relevanceValue,
        ], \JSON_THROW_ON_ERROR));

        return new CanonicalMemory(
            kind: $kind,
            sourceId: $sourceId,
            sessionId: $sessionId,
            content: $content,
            occurredAt: $occurred,
            relevance: $relevanceValue,
            contentSignature: $signature,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
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

        return [$parts[0], $parts[1]];
    }

    private function fetchRevision(\PDO $pdo): ?int
    {
        $value = $pdo->query('SELECT revision FROM om_source_revision WHERE id = 1')->fetchColumn();
        if (false === $value || null === $value) {
            return null;
        }

        return (int) $value;
    }

    private function identityFor(string $databasePath, ?int $revision): string
    {
        clearstatcache(true, $databasePath);
        $stat = @stat($databasePath);
        $dev = \is_array($stat) ? ($stat['dev'] ?? null) : null;
        $ino = \is_array($stat) ? ($stat['ino'] ?? null) : null;
        $size = \is_array($stat) ? ($stat['size'] ?? null) : null;
        $mtime = \is_array($stat) ? ($stat['mtime'] ?? null) : null;

        // Revision alone cannot prove an in-place identical-revision restore.
        // Filesystem identity and bounded size/mtime witnesses detect replacement.
        return hash('sha256', json_encode([
            realpath($databasePath) ?: $databasePath,
            $dev,
            $ino,
            $size,
            $mtime,
            $revision,
        ], \JSON_THROW_ON_ERROR));
    }

    private function wrapReadFailure(\Throwable $exception): ProjectSourceException
    {
        if ($exception instanceof ProjectSourceException) {
            return $exception;
        }

        return new ProjectSourceException(
            ProjectSourceIssueCode::OmDatabaseUnreadable,
            'Unable to read observational memory source batch.',
            previous: $exception,
        );
    }
}
