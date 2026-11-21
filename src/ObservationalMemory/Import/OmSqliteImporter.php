<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Import;

use App\Entity\MemoryGenerationStatus;
use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\ObservationalMemory\Storage\MemoryRevision;
use App\Project\HatfieldSettingsOverlay;
use App\Project\OmSourceReader;
use App\Project\ProjectSourceException;
use App\Project\ProjectSourceIssueCode;
use App\Project\ServicePathMapper;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One-way 1:1 copy of a Hatfield project's OM SQLite memory into Librarian tables.
 *
 * Rows keep their OM identities, so later observe signals continue after OM's coverage
 * watermark. Inserts are idempotent on natural keys: reruns only add new rows. The
 * SQLite file is opened `mode=ro`; each session is written in its own short transaction
 * after its source rows were read.
 */
final readonly class OmSqliteImporter
{
    /** Coverage/generation tables are copied when present. */
    public const array REQUIRED_TABLES = ['om_observation', 'om_reflection'];
    private const string DEFAULT_OM_DATABASE_PATH = '.hatfield/extensions-data/observational-memory/om.sqlite';

    public function __construct(
        private OmSourceReader $sourceReader,
        private Connection $connection,
        private ClockInterface $clock,
        private HatfieldSettingsOverlay $overlay,
        private ServicePathMapper $pathMapper,
        private string $hatfieldUserHome,
    ) {
    }

    /**
     * Explicit one-time legacy import. Resolves the OM database independently of discovery
     * results. A missing file is not marked imported. After a successful import,
     * `legacy_imported_at` blocks another import so Librarian-deleted memory stays deleted.
     *
     * @param ?string $databasePath optional absolute or project-relative source path override
     *
     * @return array<string, array{source: int, inserted: int}> per-table totals across sessions
     */
    public function import(Project $project, ?string $onlyRunId = null, ?string $databasePath = null): array
    {
        $projectId = $project->id ?? throw new \InvalidArgumentException('Project is not persisted.');
        if (null !== $project->legacyImportedAt) {
            throw new \RuntimeException('Legacy OM import already completed for this project; re-import is blocked so deleted Librarian memory stays deleted.');
        }

        $root = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
        $resolvedPath = $this->resolveDatabasePath($root, $databasePath);

        $pdo = $this->sourceReader->openReadonlyConnection($resolvedPath);
        $this->sourceReader->validateSchema($pdo);
        $tables = $this->existingTables($pdo);

        $totals = [];
        foreach ($this->runIds($pdo, $tables, $onlyRunId) as $runId) {
            $rows = $this->readRun($pdo, $tables, $runId);
            $counts = $this->connection->transactional(fn (Connection $connection): array => $this->writeRun($connection, $projectId, $runId, $rows));
            foreach ($counts as $table => $count) {
                $totals[$table]['source'] = ($totals[$table]['source'] ?? 0) + $count['source'];
                $totals[$table]['inserted'] = ($totals[$table]['inserted'] ?? 0) + $count['inserted'];
            }
        }

        $this->markImported($projectId, $resolvedPath);

        return $totals;
    }

    private function markImported(int $projectId, string $databasePath): void
    {
        $this->connection->executeStatement(
            'UPDATE projects SET database_path = ?, legacy_imported_at = ? WHERE id = ? AND legacy_imported_at IS NULL',
            [$databasePath, $this->clock->now()->format('Y-m-d H:i:s'), $projectId],
        );
    }

    /**
     * Resolve the legacy OM database without discovery. Explicit --database wins;
     * otherwise use extensions.settings.observational_memory.storage.database or the default path.
     */
    private function resolveDatabasePath(string $projectRoot, ?string $databasePath): string
    {
        $logical = null !== $databasePath && '' !== trim($databasePath)
            ? trim($databasePath)
            : $this->configuredDatabasePath($projectRoot);

        if (!str_starts_with($logical, '/')) {
            $logical = rtrim($projectRoot, '/').'/'.ltrim($logical, '/');
        }

        $mapped = $this->pathMapper->mapSourcePath($logical);
        $resolved = realpath($mapped);

        return false === $resolved ? $mapped : $resolved;
    }

    private function configuredDatabasePath(string $projectRoot): string
    {
        $settings = [];
        $userHome = trim($this->hatfieldUserHome);
        if ('' !== $userHome) {
            $userSettings = $this->pathMapper->map(rtrim($userHome, '/').'/.hatfield/settings.yaml');
            $settings = $this->overlay->overlay($settings, $this->loadYamlFile($userSettings));
        }
        $projectSettings = rtrim($projectRoot, '/').'/.hatfield/settings.yaml';
        $settings = $this->overlay->overlay($settings, $this->loadYamlFile($projectSettings));

        $extensions = $settings['extensions'] ?? null;
        $extensionSettings = \is_array($extensions) ? ($extensions['settings'] ?? null) : null;
        $om = \is_array($extensionSettings) ? ($extensionSettings['observational_memory'] ?? null) : null;
        if (\is_array($om)) {
            $storage = $om['storage'] ?? null;
            if (\is_array($storage) && isset($storage['database']) && \is_string($storage['database']) && '' !== $storage['database']) {
                return $storage['database'];
            }
        }

        return self::DEFAULT_OM_DATABASE_PATH;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadYamlFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if (false === $content) {
            return [];
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SettingsMalformed, 'Hatfield settings YAML is malformed.');
        }

        return \is_array($parsed) ? $parsed : [];
    }

    /** @return array<string, true> */
    private function existingTables(\PDO $pdo): array
    {
        $existing = array_fill_keys($this->fetchColumn($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"), true);
        $missing = array_values(array_diff(self::REQUIRED_TABLES, array_keys($existing)));
        if ([] !== $missing) {
            throw new \RuntimeException(\sprintf('OM database is missing table(s): %s.', implode(', ', $missing)));
        }

        return $existing;
    }

    /**
     * @param array<string, true> $tables
     *
     * @return list<string>
     */
    private function runIds(\PDO $pdo, array $tables, ?string $onlyRunId): array
    {
        $selects = [];
        foreach (['om_observation', 'om_coverage', 'om_reflection', 'om_memory_generation'] as $table) {
            if (isset($tables[$table])) {
                $selects[] = 'SELECT run_id FROM '.$table;
            }
        }
        $runIds = $this->fetchColumn($pdo, implode(' UNION ', $selects).' ORDER BY run_id');

        return null === $onlyRunId ? $runIds : array_values(array_filter($runIds, static fn (string $id): bool => $id === $onlyRunId));
    }

    /** @return list<string> */
    private function fetchColumn(\PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if (false === $statement) {
            throw new \RuntimeException('OM database query failed.');
        }

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, true> $tables
     *
     * @return array{
     *   observations: list<array<string, mixed>>,
     *   coverage: list<array<string, mixed>>,
     *   reflections: list<array<string, mixed>>,
     *   generations: list<array<string, mixed>>,
     *   generation_reflections: list<array<string, mixed>>,
     *   retained_observations: list<array<string, mixed>>,
     *   active_generation_id: ?string
     * }
     */
    private function readRun(\PDO $pdo, array $tables, string $runId): array
    {
        $fetch = static function (string $sql, array $extra = []) use ($pdo, $tables, $runId): array {
            // Skip optional tables an older or partial OM schema does not have.
            preg_match_all('/\b(?:FROM|JOIN) (om_[a-z_]+)/', $sql, $matches);
            foreach ($matches[1] as $table) {
                if (!isset($tables[$table])) {
                    return [];
                }
            }
            $statement = $pdo->prepare($sql);
            $statement->execute([$runId, ...$extra]);

            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        };

        $pdo->beginTransaction();
        try {
            $rows = [
                'observations' => $fetch(
                    'SELECT observation_id, boundary_key, source_start_seq, source_end_seq, source_refs_json, content,
                            content_hash, relevance, timestamp, token_count, observer_model, observer_schema_version, created_at
                     FROM om_observation WHERE run_id = ? ORDER BY source_start_seq, observation_id',
                ),
                'coverage' => $fetch(
                    'SELECT coverage_key, boundary_key, source_start_seq, source_end_seq, chunk_key, part_index, part_count,
                            source_digest, part_digest, renderer_version, observer_schema_version, observation_count, covered_at
                     FROM om_coverage WHERE run_id = ? ORDER BY source_start_seq, part_index',
                ),
                'reflections' => $fetch(
                    'SELECT reflection_id, observation_set_hash, content, supporting_observation_ids_json, token_count,
                            reflector_model, reflector_schema_version, created_at
                     FROM om_reflection WHERE run_id = ? ORDER BY created_at, reflection_id',
                ),
                // A running OM generation is still in flight in Hatfield; a rerun imports it once terminal.
                'generations' => $fetch(
                    'SELECT generation_id, status, observation_set_hash, reflector_model, reflector_schema_version,
                            required_start_seq, required_end_seq, failure_code, created_at, completed_at
                     FROM om_memory_generation WHERE run_id = ? AND status <> ? ORDER BY created_at, generation_id',
                    [MemoryGenerationStatus::Running->value],
                ),
                'generation_reflections' => $fetch(
                    'SELECT gr.generation_id, gr.reflection_id, gr.position FROM om_generation_reflection gr
                     INNER JOIN om_memory_generation g ON g.generation_id = gr.generation_id
                     WHERE g.run_id = ? ORDER BY gr.generation_id, gr.position',
                ),
                'retained_observations' => $fetch(
                    'SELECT ro.generation_id, ro.observation_id, ro.position FROM om_generation_retained_observation ro
                     INNER JOIN om_memory_generation g ON g.generation_id = ro.generation_id
                     WHERE g.run_id = ? ORDER BY ro.generation_id, ro.position',
                ),
            ];
            $active = $fetch('SELECT generation_id FROM om_active_generation WHERE run_id = ?');
        } finally {
            $pdo->rollBack();
        }

        $rows['active_generation_id'] = [] === $active ? null : (string) $active[0]['generation_id'];

        return $rows;
    }

    /**
     * @param array{
     *   observations: list<array<string, mixed>>,
     *   coverage: list<array<string, mixed>>,
     *   reflections: list<array<string, mixed>>,
     *   generations: list<array<string, mixed>>,
     *   generation_reflections: list<array<string, mixed>>,
     *   retained_observations: list<array<string, mixed>>,
     *   active_generation_id: ?string
     * } $rows
     *
     * @return array<string, array{source: int, inserted: int}>
     */
    private function writeRun(Connection $connection, int $projectId, string $runId, array $rows): array
    {
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO om_sessions (project_id, source_kind, session_id, parent_session_id, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?)
             ON CONFLICT (project_id, source_kind, session_id) DO NOTHING',
            [$projectId, MemorySourceKind::Hatfield->value, $runId, $timestamp, $timestamp],
        );
        $sessionPk = (int) $connection->fetchOne(
            'SELECT id FROM om_sessions WHERE project_id = ? AND source_kind = ? AND session_id = ? FOR UPDATE',
            [$projectId, MemorySourceKind::Hatfield->value, $runId],
        );

        $counts = [
            // Insert-only: Librarian owns memory after the cutover, so a (re-)import never
            // overwrites rows Librarian already has.
            'om_observations' => $this->insertAll($connection, 'om_observations', $sessionPk, $rows['observations'], '(memory_session_id, observation_id)'),
            'om_observer_coverage' => $this->insertAll($connection, 'om_observer_coverage', $sessionPk, $rows['coverage'], '(memory_session_id, coverage_key)'),
            'om_reflections' => $this->insertAll($connection, 'om_reflections', $sessionPk, $rows['reflections'], '(memory_session_id, reflection_id)'),
        ];

        // Generations are immutable once terminal: links are copied only with a newly inserted row.
        $linksByGeneration = [];
        foreach (['generation_reflections' => 'om_generation_reflections', 'retained_observations' => 'om_generation_retained_observations'] as $key => $table) {
            foreach ($rows[$key] as $link) {
                $generationId = (string) $link['generation_id'];
                unset($link['generation_id']);
                $linksByGeneration[$generationId][$table][] = $link;
            }
        }

        $counts['om_memory_generations'] = ['source' => \count($rows['generations']), 'inserted' => 0];
        $counts['om_generation_reflections'] = ['source' => 0, 'inserted' => 0];
        $counts['om_generation_retained_observations'] = ['source' => 0, 'inserted' => 0];
        foreach ($rows['generations'] as $generation) {
            $generationPk = $connection->fetchOne(
                'INSERT INTO om_memory_generations
                    (memory_session_id, generation_id, status, observation_set_hash, reflector_model, reflector_schema_version,
                     required_start_seq, required_end_seq, failure_code, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (memory_session_id, generation_id) DO NOTHING
                 RETURNING id',
                [$sessionPk, ...array_values($generation)],
            );
            $links = $linksByGeneration[(string) $generation['generation_id']] ?? [];
            foreach ($links as $table => $tableLinks) {
                $counts[$table]['source'] += \count($tableLinks);
            }
            if (false === $generationPk) {
                continue;
            }
            ++$counts['om_memory_generations']['inserted'];
            foreach ($links as $table => $tableLinks) {
                foreach ($tableLinks as $link) {
                    $connection->insert($table, ['generation_id' => (int) $generationPk, ...$link]);
                    ++$counts[$table]['inserted'];
                }
            }
        }

        $changed = 0;
        foreach ($counts as $count) {
            $changed += $count['inserted'];
        }

        if (null !== $rows['active_generation_id']) {
            // The importer only moves pointers it owns: an unset pointer, or one still on a generation
            // that om.sqlite knows (OM advanced). A generation absent from om.sqlite was produced by
            // Librarian after cutover and is never replaced by an older imported one.
            $connection->executeStatement(
                'UPDATE om_sessions SET active_generation_id = g.id, updated_at = :updated_at
                 FROM om_memory_generations g
                 WHERE om_sessions.id = :session AND g.memory_session_id = om_sessions.id
                   AND g.generation_id = :active AND g.status = :succeeded
                   AND om_sessions.active_generation_id IS DISTINCT FROM g.id
                   AND (om_sessions.active_generation_id IS NULL OR EXISTS (
                       SELECT 1 FROM om_memory_generations current
                       WHERE current.id = om_sessions.active_generation_id AND current.generation_id IN (:imported)
                   ))',
                [
                    'updated_at' => $timestamp,
                    'session' => $sessionPk,
                    'active' => $rows['active_generation_id'],
                    'succeeded' => MemoryGenerationStatus::Succeeded->value,
                    'imported' => array_map(static fn (array $generation): string => (string) $generation['generation_id'], $rows['generations']),
                ],
                ['imported' => ArrayParameterType::STRING],
            );
        }
        if ($changed > 0) {
            MemoryRevision::bumpForProject($connection, $projectId);
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows column => value in target column order
     *
     * @return array{source: int, inserted: int}
     */
    private function insertAll(Connection $connection, string $table, int $sessionPk, array $rows, string $conflictTarget): array
    {
        $inserted = 0;
        foreach ($rows as $row) {
            $columns = ['memory_session_id', ...array_keys($row)];
            $inserted += $connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT %s DO NOTHING',
                    $table,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, \count($columns), '?')),
                    $conflictTarget,
                ),
                [$sessionPk, ...array_values($row)],
            );
        }

        return ['source' => \count($rows), 'inserted' => $inserted];
    }
}
