<?php

declare(strict_types=1);

/**
 * Builds synthetic Hatfield project-source fixtures for plan 03 browser acceptance.
 *
 * Target (host and container writable path): var/browser-live/project-sources
 * Application/worker readonly mount: /project-sources
 *
 * Does not boot Hatfield, load extension PHP, run OM migrations, or copy owner data.
 */
/** Fixed ownership marker content; not a cross-run checksum/version manifest. */
const PROJECT_SOURCES_OWNERSHIP_MARKER = "librarian-plan03-synthetic-project-sources\n";
const PROJECT_SOURCES_OWNERSHIP_MARKER_BASENAME = 'SYNTHETIC_PROJECT_SOURCES';
const OM_EXTENSION_FQCN = 'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension';
const OM_SCHEMA_VERSIONS = [
    '20260722_001_domain' => 'OM domain tables: observation, coverage, reflection, compaction request/result',
    '20260725_002_reflection_multi_and_indexes' => 'Allow multiple reflections per request; add coverage/observation/request indexes',
    '20260726_003_active_generation_and_relevance_text' => 'Relevance TEXT + timestamp; chunk/part coverage; request_fingerprint; active generation tables',
    '20260729_004_current_activity' => 'Ephemeral om_current_activity for live TUI status notices',
    '20260922_005_semantic_health' => 'Durable health and restart state for derived semantic storage',
    '20261021_006_semantic_source_revisions' => 'Transactional canonical source revisions and incremental semantic reconciliation progress',
];

const PROJECT_SOURCE_SLUGS = ['default-om', 'custom-relative'];

$projectDir = dirname(__DIR__, 2);
$targetRoot = $projectDir.'/var/browser-live/project-sources';

assertExactNonSymlinkedPath($projectDir.'/var');
assertExactNonSymlinkedPath($projectDir.'/var/browser-live');
if (file_exists($targetRoot)) {
    assertExactNonSymlinkedPath($targetRoot);
    assertPreparedProjectSources($targetRoot);
    echo "Synthetic project sources already prepared at {$targetRoot}\n";
    exit(0);
}

if (!is_dir(dirname($targetRoot)) && !mkdir(dirname($targetRoot), 0775, true) && !is_dir(dirname($targetRoot))) {
    throw new RuntimeException('Unable to create browser-live directory for project sources.');
}

if (!mkdir($targetRoot, 0775, true) && !is_dir($targetRoot)) {
    throw new RuntimeException('Unable to create project-sources root.');
}
assertExactNonSymlinkedPath($targetRoot);

buildContextHome($targetRoot.'/context-home');
buildContextApp($targetRoot.'/context-app');
$projectsRoot = $targetRoot.'/projects';
if (!mkdir($projectsRoot, 0775, true) && !is_dir($projectsRoot)) {
    throw new RuntimeException('Unable to create projects fixture root.');
}

buildDefaultOmProject($projectsRoot.'/default-om');
buildCustomRelativeProject($projectsRoot.'/custom-relative');

writeFile($targetRoot.'/'.PROJECT_SOURCES_OWNERSHIP_MARKER_BASENAME, PROJECT_SOURCES_OWNERSHIP_MARKER);
assertPreparedProjectSources($targetRoot);
echo "Prepared synthetic project sources at {$targetRoot}\n";
echo "Visible mount: /project-sources\n";
echo "HATFIELD_USER_HOME=/synthetic-home -> /project-sources/context-home\n";
echo "HATFIELD_APP_ROOT=/synthetic-app -> /project-sources/context-app\n";

function assertPreparedProjectSources(string $targetRoot): void
{
    $markerPath = $targetRoot.'/'.PROJECT_SOURCES_OWNERSHIP_MARKER_BASENAME;
    if (!is_file($markerPath)) {
        throw new RuntimeException(sprintf('Project-sources tree exists at %s but is missing the synthetic ownership marker. Refusing automatic rebuild; remove the tree explicitly after verifying ownership if recreation is needed.', $targetRoot));
    }
    if (PROJECT_SOURCES_OWNERSHIP_MARKER !== (string) file_get_contents($markerPath)) {
        throw new RuntimeException(sprintf('Project-sources tree at %s has a foreign ownership marker. Refusing to modify or rebuild it.', $targetRoot));
    }
    if (!is_dir($targetRoot.'/context-home') || !is_dir($targetRoot.'/context-app') || !is_dir($targetRoot.'/projects')) {
        throw new RuntimeException(sprintf('Project-sources tree at %s is incomplete (missing context-home, context-app, or projects). Refusing automatic rebuild.', $targetRoot));
    }
    foreach (PROJECT_SOURCE_SLUGS as $slug) {
        if (!is_dir($targetRoot.'/projects/'.$slug)) {
            throw new RuntimeException(sprintf('Project-sources tree at %s is incomplete (missing projects/%s). Refusing automatic rebuild.', $targetRoot, $slug));
        }
    }
}

function assertExactNonSymlinkedPath(string $path): void
{
    if (is_link($path)) {
        throw new RuntimeException(sprintf('Refusing symlinked path: %s', $path));
    }
    $parent = dirname($path);
    if ('/' !== $parent && '.' !== $parent && file_exists($parent) && is_link($parent)) {
        throw new RuntimeException(sprintf('Refusing path under symlink parent: %s', $path));
    }
}

function buildContextHome(string $path): void
{
    writeFile($path.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    enabled:
        - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
YAML);
}

function buildContextApp(string $path): void
{
    writeFile($path.'/README.md', "Synthetic Hatfield app root for %kernel.project_dir% resolution.\n");
}

function buildDefaultOmProject(string $path): void
{
    initGitProject($path, 'git://librarian-fixtures/default-om.git');
    writeFile($path.'/.hatfield/settings.yaml', <<<'YAML'
# Project settings omitted for OM storage: defaults apply.
sessions:
    path: '.hatfield/sessions'
YAML);
    $db = $path.'/.hatfield/extensions-data/observational-memory/om.sqlite';
    createSupportedOmDatabase($db, observationCount: 2, reflectionCount: 1, sourceRevision: 4, sourceChangeCount: 3);
    writeSessionJournal($path.'/.hatfield/sessions/108/events.jsonl', '108', 1);
}

function buildCustomRelativeProject(string $path): void
{
    initGitProject($path, 'git://librarian-fixtures/custom-relative.git');
    writeFile($path.'/.hatfield/settings.yaml', <<<'YAML'
sessions:
    path: '.hatfield/custom-sessions'
observational_memory:
    storage:
        database: '.hatfield/custom-om/memory.sqlite'
YAML);
    createSupportedOmDatabase($path.'/.hatfield/custom-om/memory.sqlite', observationCount: 1, reflectionCount: 1, sourceRevision: 2, sourceChangeCount: 2);
    writeSessionJournal($path.'/.hatfield/custom-sessions/7/events.jsonl', '7', 1);
}

function initGitProject(string $path, string $origin): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Unable to create project path %s', $path));
    }
    writeFile($path.'/README.md', sprintf("Synthetic fixture for %s\n", basename($path)));
    $gitDir = $path.'/.git';
    if (!is_dir($gitDir)) {
        runCommand(['git', 'init', '-q', $path]);
        runCommand(['git', '-C', $path, 'config', 'user.email', 'fixtures@librarian.test']);
        runCommand(['git', '-C', $path, 'config', 'user.name', 'Librarian Fixtures']);
        runCommand(['git', '-C', $path, 'add', 'README.md']);
        runCommand(['git', '-C', $path, '-c', 'commit.gpgsign=false', 'commit', '-qm', 'synthetic fixture']);
    }
    runCommand(['git', '-C', $path, 'remote', 'remove', 'origin'], allowFailure: true);
    runCommand(['git', '-C', $path, 'remote', 'add', 'origin', $origin]);
}

function createSupportedOmDatabase(
    string $dbPath,
    int $observationCount,
    int $reflectionCount,
    int $sourceRevision,
    int $sourceChangeCount,
    string $runId = '108',
    string $idPrefix = 'fx',
): void {
    if (!is_dir(dirname($dbPath)) && !mkdir(dirname($dbPath), 0775, true) && !is_dir(dirname($dbPath))) {
        throw new RuntimeException(sprintf('Unable to create OM directory for %s', $dbPath));
    }
    if (is_file($dbPath)) {
        unlink($dbPath);
    }
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE om_schema_version (
    version TEXT PRIMARY KEY NOT NULL,
    description TEXT NOT NULL,
    checksum TEXT NOT NULL,
    applied_at TEXT NOT NULL
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE om_observation (
    observation_id TEXT PRIMARY KEY NOT NULL,
    run_id TEXT NOT NULL,
    boundary_key TEXT NOT NULL,
    source_start_seq INTEGER NOT NULL,
    source_end_seq INTEGER NOT NULL,
    source_refs_json TEXT NOT NULL,
    content TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    relevance TEXT NOT NULL CHECK (relevance IN ('low','medium','high','critical')),
    timestamp TEXT NOT NULL,
    token_count INTEGER NOT NULL,
    observer_model TEXT NOT NULL,
    observer_schema_version TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE om_reflection (
    reflection_id TEXT PRIMARY KEY NOT NULL,
    run_id TEXT NOT NULL,
    compaction_request_id TEXT NOT NULL,
    observation_set_hash TEXT NOT NULL,
    content TEXT NOT NULL,
    supporting_observation_ids_json TEXT NOT NULL,
    compression_level TEXT NOT NULL,
    token_count INTEGER NOT NULL,
    reflector_model TEXT NOT NULL,
    reflector_schema_version TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL);
    $pdo->exec('CREATE TABLE om_source_revision (id INTEGER PRIMARY KEY CHECK (id = 1), revision INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE om_source_change (kind TEXT NOT NULL, source_id TEXT NOT NULL, revision INTEGER NOT NULL, PRIMARY KEY (kind, source_id))');

    $appliedAt = '2026-01-01T00:00:00+00:00';
    $insertVersion = $pdo->prepare('INSERT INTO om_schema_version (version, description, checksum, applied_at) VALUES (?, ?, ?, ?)');
    foreach (OM_SCHEMA_VERSIONS as $version => $description) {
        $insertVersion->execute([$version, $description, hash('sha256', $version), $appliedAt]);
    }

    $insertObservation = $pdo->prepare(<<<'SQL'
INSERT INTO om_observation (
    observation_id, run_id, boundary_key, source_start_seq, source_end_seq, source_refs_json,
    content, content_hash, relevance, timestamp, token_count, observer_model, observer_schema_version, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
    for ($i = 1; $i <= $observationCount; ++$i) {
        $content = sprintf('synthetic observation %s-%d', $idPrefix, $i);
        $insertObservation->execute([
            sprintf('%s-obs-%d', $idPrefix, $i),
            $runId,
            'boundary-1',
            $i,
            $i,
            '[]',
            $content,
            hash('sha256', $content),
            'medium',
            '2026-01-01 00:00',
            12,
            'fixture-model',
            '1',
            $appliedAt,
        ]);
    }

    $insertReflection = $pdo->prepare(<<<'SQL'
INSERT INTO om_reflection (
    reflection_id, run_id, compaction_request_id, observation_set_hash, content,
    supporting_observation_ids_json, compression_level, token_count, reflector_model, reflector_schema_version, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
    for ($i = 1; $i <= $reflectionCount; ++$i) {
        $content = sprintf('synthetic reflection %s-%d', $idPrefix, $i);
        $insertReflection->execute([
            sprintf('%s-ref-%d', $idPrefix, $i),
            $runId,
            sprintf('%s-req-%d', $idPrefix, $i),
            hash('sha256', $idPrefix.'-set-'.$i),
            $content,
            '[]',
            'low',
            20,
            'fixture-model',
            '1',
            $appliedAt,
        ]);
    }

    $pdo->exec(sprintf('INSERT INTO om_source_revision (id, revision) VALUES (1, %d)', $sourceRevision));
    $insertChange = $pdo->prepare('INSERT INTO om_source_change (kind, source_id, revision) VALUES (?, ?, ?)');
    $written = 0;
    for ($i = 1; $i <= $observationCount && $written < $sourceChangeCount; ++$i) {
        $insertChange->execute(['observation', sprintf('%s-obs-%d', $idPrefix, $i), $i]);
        ++$written;
    }
    for ($i = 1; $i <= $reflectionCount && $written < $sourceChangeCount; ++$i) {
        $insertChange->execute(['reflection', sprintf('%s-ref-%d', $idPrefix, $i), $observationCount + $i]);
        ++$written;
    }
}

function writeSessionJournal(string $path, string $runId, int $eventCount): void
{
    $lines = [];
    for ($seq = 1; $seq <= $eventCount; ++$seq) {
        $lines[] = json_encode([
            'schema_version' => '1.0',
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [
                'fixture' => true,
                'note' => 'synthetic sanitized event',
            ],
            'ts' => sprintf('2026-01-01T00:00:%02d+00:00', $seq),
        ], \JSON_THROW_ON_ERROR);
    }
    writeFile($path, implode("\n", $lines)."\n");
}

function writeFile(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Unable to create directory %s', $dir));
    }
    if (false === file_put_contents($path, $contents)) {
        throw new RuntimeException(sprintf('Unable to write %s', $path));
    }
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, bool $allowFailure = false): void
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: '.implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (0 !== $exit && !$allowFailure) {
        throw new RuntimeException(sprintf("Command failed (%d): %s\n%s%s", $exit, implode(' ', $command), $stdout, $stderr));
    }
}
