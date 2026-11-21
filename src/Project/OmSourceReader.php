<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Opens legacy OM SQLite sources read-only for the one-time importer.
 *
 * Uses PDO SQLite readonly URI so WAL sidecars remain visible. Never migrates,
 * checkpoints, creates databases, or writes source tables.
 */
final class OmSourceReader
{
    /**
     * Shared read-only PDO opener for the legacy importer.
     *
     * Callers own short read transactions and must close the connection before
     * model or derived-store work. Never migrates or writes source tables.
     */
    public function openReadonlyConnection(string $databasePath): \PDO
    {
        if (!is_file($databasePath)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseMissing, 'Observational memory database file is missing.');
        }

        if (!is_readable($databasePath)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Observational memory database file is unreadable.');
        }

        return $this->openReadonly($databasePath);
    }

    /** @return list<string> */
    public function validateSchema(\PDO $pdo): array
    {
        $applied = $this->readAppliedVersions($pdo);
        $hasSourceRevisions = $this->assertSupportedSchema($applied);
        $this->assertRequiredTables($pdo, $hasSourceRevisions);

        return $applied;
    }

    private function openReadonly(string $databasePath): \PDO
    {
        // PDO requires the sqlite: driver prefix. Keep mode=ro and avoid immutable
        // so associated WAL/SHM state remains visible.
        $uri = 'sqlite:file:'.$this->encodeSqliteUriPath($databasePath).'?mode=ro';

        try {
            $pdo = new \PDO($uri, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $exception) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmDatabaseUnreadable, 'Unable to open observational memory database read-only.', previous: $exception);
        }

        return $pdo;
    }

    private function encodeSqliteUriPath(string $databasePath): string
    {
        $segments = explode('/', str_replace('\\', '/', $databasePath));
        $encoded = [];
        foreach ($segments as $segment) {
            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }

    /**
     * @return list<string>
     */
    private function readAppliedVersions(\PDO $pdo): array
    {
        try {
            $statement = $pdo->query('SELECT version FROM om_schema_version ORDER BY version ASC');
        } catch (\PDOException $exception) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, 'Observational memory schema version table is missing or unreadable.', previous: $exception);
        }

        $versions = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $version) {
            if (\is_string($version) && '' !== $version) {
                $versions[] = $version;
            }
        }

        if ([] === $versions) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, 'Observational memory schema version table is empty.');
        }

        return $versions;
    }

    /**
     * @param list<string> $applied
     */
    private function assertSupportedSchema(array $applied): bool
    {
        $required = OmExtensionIds::REQUIRED_SCHEMA_VERSIONS;
        $unknown = array_values(array_diff($applied, $required));
        if ([] !== $unknown) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaUnknown, 'Observational memory schema contains unsupported version(s).');
        }

        $missing = array_values(array_diff($required, $applied));
        if ([OmExtensionIds::SOURCE_REVISIONS_SCHEMA_VERSION] === $missing) {
            return false;
        }
        if ([] !== $missing) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaUnsupported, 'Observational memory schema is missing required version(s).');
        }

        return true;
    }

    private function assertRequiredTables(\PDO $pdo, bool $hasSourceRevisions): void
    {
        $requiredColumns = [
            'om_observation' => ['observation_id', 'run_id', 'content', 'relevance', 'timestamp'],
            'om_reflection' => ['reflection_id', 'run_id', 'content', 'created_at'],
        ];
        if ($hasSourceRevisions) {
            $requiredColumns['om_source_revision'] = ['id', 'revision'];
            $requiredColumns['om_source_change'] = ['kind', 'source_id', 'revision'];
        }

        foreach ($requiredColumns as $table => $columns) {
            $existing = $this->tableColumns($pdo, $table);
            foreach ($columns as $column) {
                if (!isset($existing[$column])) {
                    throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, \sprintf('Observational memory table "%s" is missing required column "%s".', $table, $column));
                }
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function tableColumns(\PDO $pdo, string $table): array
    {
        if (1 !== preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, 'Invalid observational memory table name.');
        }

        try {
            $statement = $pdo->query('PRAGMA table_info('.$table.')');
        } catch (\PDOException $exception) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, \sprintf('Observational memory table "%s" is missing or unreadable.', $table), previous: $exception);
        }

        $columns = [];
        foreach ($statement->fetchAll() as $row) {
            $name = $row['name'] ?? null;
            if (\is_string($name) && '' !== $name) {
                $columns[$name] = true;
            }
        }

        if ([] === $columns) {
            throw new ProjectSourceException(ProjectSourceIssueCode::OmSchemaIncomplete, \sprintf('Observational memory table "%s" is missing or unreadable.', $table));
        }

        return $columns;
    }
}
