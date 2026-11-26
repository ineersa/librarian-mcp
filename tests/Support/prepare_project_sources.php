<?php

declare(strict_types=1);

use App\Index\Source\MemoryKind;
use App\Memory\ClaudeTranscriptReader;
use App\Tests\Support\ProjectSourceFixture;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

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
const CLAUDE_PROJECTS_DIRECTORY = 'claude-projects';
const CLAUDE_FIXTURE_SESSION_ID = '0f0e0d0c-0b0a-4908-8706-050403020100';

$projectDir = dirname(__DIR__, 2);
$targetRoot = $projectDir.'/var/browser-live/project-sources';

assertExactNonSymlinkedPath($projectDir.'/var');
assertExactNonSymlinkedPath($projectDir.'/var/browser-live');
if (file_exists($targetRoot)) {
    assertExactNonSymlinkedPath($targetRoot);
    assertPreparedProjectSources($targetRoot);
    refreshDefaultOmRecallFixtures($targetRoot.'/projects/default-om');
    prepareMemoryToolProjects($targetRoot);
    ensureFixtureOmGenerationTables($targetRoot);
    prepareBackupFixtures($targetRoot);
    prepareClaudeTranscripts($targetRoot);
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
prepareMemoryToolProjects($targetRoot);
ensureFixtureOmGenerationTables($targetRoot);
prepareBackupFixtures($targetRoot);
prepareClaudeTranscripts($targetRoot);
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
    createSupportedOmDatabase(
        $db,
        observationCount: 2,
        reflectionCount: 1,
        sourceRevision: 4,
        sourceChangeCount: 3,
        runId: '108',
        idPrefix: 'fx',
        canonicalIds: true,
    );
    writeSessionJournal($path.'/.hatfield/sessions/108/events.jsonl', '108', 2);
}

function refreshDefaultOmRecallFixtures(string $path): void
{
    assertExactNonSymlinkedPath($path);
    $db = $path.'/.hatfield/extensions-data/observational-memory/om.sqlite';
    createSupportedOmDatabase(
        $db,
        observationCount: 2,
        reflectionCount: 1,
        sourceRevision: 4,
        sourceChangeCount: 3,
        runId: '108',
        idPrefix: 'fx',
        canonicalIds: true,
    );
    writeSessionJournal($path.'/.hatfield/sessions/108/events.jsonl', '108', 2);
}

/**
 * One synthetic Claude Code transcript for default-om at CLAUDE_PROJECTS_PATH
 * (/project-sources/claude-projects in the browser stack), for the backfill action.
 */
function prepareClaudeTranscripts(string $targetRoot): void
{
    $directory = $targetRoot.'/'.CLAUDE_PROJECTS_DIRECTORY.'/'.ClaudeTranscriptReader::encodeProjectRoot(ProjectSourceFixture::visibleProjectPath('default-om'));
    $lines = [
        ['type' => 'user', 'isSidechain' => false, 'sessionId' => CLAUDE_FIXTURE_SESSION_ID, 'timestamp' => '2026-11-25T10:00:00.000Z',
            'message' => ['role' => 'user', 'content' => 'synthetic claude question fx-claude']],
        ['type' => 'assistant', 'isSidechain' => false, 'sessionId' => CLAUDE_FIXTURE_SESSION_ID, 'timestamp' => '2026-11-25T10:00:05.000Z',
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'synthetic claude answer fx-claude']]]],
    ];
    writeFile(
        $directory.'/'.CLAUDE_FIXTURE_SESSION_ID.'.jsonl',
        implode('', array_map(static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR)."\n", $lines)),
    );
}

function prepareBackupFixtures(string $targetRoot): void
{
    // Backup inputs for default-om: Hatfield application state (WAL mode, like Hatfield), plus a log
    // and synthetic credential files the backup skips. Add only missing files so trees prepared before these fixtures still match.
    $hatfield = $targetRoot.'/projects/default-om/.hatfield';
    assertExactNonSymlinkedPath($hatfield);
    if (!file_exists($hatfield.'/state.sqlite')) {
        createHatfieldStateDatabase($hatfield.'/state.sqlite', '108');
    }
    if (!file_exists($hatfield.'/logs/agent.log')) {
        writeFile($hatfield.'/logs/agent.log', "synthetic log line\n");
    }
    foreach (['auth.json', 'chatgpt-auth.json'] as $credentials) {
        if (!file_exists($hatfield.'/'.$credentials)) {
            writeFile($hatfield.'/'.$credentials, "{\"synthetic\": true}\n");
        }
    }
}

function createHatfieldStateDatabase(string $dbPath, string $sessionId): void
{
    if (is_file($dbPath)) {
        unlink($dbPath);
    }
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $statement = $db->prepare('INSERT INTO hatfield_session (id, name) VALUES (:id, :name)');
    $statement->bindValue(':id', (int) $sessionId, \SQLITE3_INTEGER);
    $statement->bindValue(':name', 'Synthetic session '.$sessionId, \SQLITE3_TEXT);
    $statement->execute();
    $db->close();
}

function buildCustomRelativeProject(string $path): void
{
    initGitProject($path, 'git://librarian-fixtures/custom-relative.git');
    writeFile($path.'/.hatfield/settings.yaml', <<<'YAML'
sessions:
    path: '.hatfield/custom-sessions'
extensions:
    settings:
        observational_memory:
            storage:
                database: '.hatfield/custom-om/memory.sqlite'
YAML);
    createSupportedOmDatabase($path.'/.hatfield/custom-om/memory.sqlite', observationCount: 1, reflectionCount: 1, sourceRevision: 2, sourceChangeCount: 2);
    writeSessionJournal($path.'/.hatfield/custom-sessions/7/events.jsonl', '7', 1);
}

/**
 * Idempotently add OM generation tables/links for harness-owned fixture DBs.
 * Never recreates databases or writes outside the verified synthetic tree.
 */
function ensureFixtureOmGenerationTables(string $targetRoot): void
{
    assertPreparedProjectSources($targetRoot);

    /** @var list<array{slug: string, relative: string, runId: string, idPrefix: string}> $fixtures */
    $fixtures = [
        ['slug' => 'default-om', 'relative' => '.hatfield/extensions-data/observational-memory/om.sqlite', 'runId' => '108', 'idPrefix' => 'fx'],
        ['slug' => 'custom-relative', 'relative' => '.hatfield/custom-om/memory.sqlite', 'runId' => '108', 'idPrefix' => 'fx'],
        ['slug' => 'mcp-a', 'relative' => '.hatfield/extensions-data/observational-memory/om.sqlite', 'runId' => '108', 'idPrefix' => 'mcp-a'],
        ['slug' => 'mcp-b', 'relative' => '.hatfield/extensions-data/observational-memory/om.sqlite', 'runId' => '108', 'idPrefix' => 'mcp-b'],
    ];

    foreach ($fixtures as $fixture) {
        $projectPath = $targetRoot.'/projects/'.$fixture['slug'];
        if (!is_dir($projectPath)) {
            continue;
        }
        assertExactNonSymlinkedPath($projectPath);

        $dbPath = $projectPath.'/'.$fixture['relative'];
        if (!is_file($dbPath)) {
            continue;
        }
        assertExactNonSymlinkedPath(dirname($dbPath));
        assertExactNonSymlinkedPath($dbPath);

        $realRoot = realpath($targetRoot.'/projects');
        $realDb = realpath($dbPath);
        if (false === $realRoot || false === $realDb || !str_starts_with($realDb, $realRoot.\DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(sprintf('Refusing fixture mutation outside owned projects root: %s', $dbPath));
        }

        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        ensureOmGenerationProjection($pdo, $fixture['runId'], $fixture['idPrefix']);
    }
}

function prepareMemoryToolProjects(string $targetRoot): void
{
    // Add only missing, harness-owned fixtures; never rebuild existing source trees.
    foreach (['mcp-a', 'mcp-b'] as $slug) {
        $path = $targetRoot.'/projects/'.$slug;
        if (file_exists($path)) {
            assertExactNonSymlinkedPath($path);
            continue;
        }
        initGitProject($path, 'git://librarian-fixtures/'.$slug.'.git');
        writeFile($path.'/.hatfield/settings.yaml', "sessions:\n    path: '.hatfield/sessions'\n");
        createSupportedOmDatabase(
            $path.'/.hatfield/extensions-data/observational-memory/om.sqlite',
            observationCount: 2, reflectionCount: 1, sourceRevision: 3, sourceChangeCount: 3,
            runId: '108', idPrefix: $slug, canonicalIds: true,
        );
        writeSessionJournal($path.'/.hatfield/sessions/108/events.jsonl', '108', 2);
    }
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
    bool $canonicalIds = false,
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
            $canonicalIds ? ProjectSourceFixture::memoryId(MemoryKind::Observation, $i) : sprintf('%s-obs-%d', $idPrefix, $i),
            $runId,
            'boundary-1',
            $i,
            $i,
            $canonicalIds ? json_encode([['run_id' => $runId, 'seq' => $i]], \JSON_THROW_ON_ERROR) : '[]',
            $content,
            hash('sha256', $content),
            'medium',
            $canonicalIds ? sprintf('2026-01-%02d 10:30', $i) : '2026-01-01 00:00',
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
            $canonicalIds ? ProjectSourceFixture::memoryId(MemoryKind::Reflection, $i) : sprintf('%s-ref-%d', $idPrefix, $i),
            $runId,
            sprintf('%s-req-%d', $idPrefix, $i),
            hash('sha256', $idPrefix.'-set-'.$i),
            $content,
            $canonicalIds ? json_encode([ProjectSourceFixture::memoryId(MemoryKind::Observation, 1)], \JSON_THROW_ON_ERROR) : '[]',
            'low',
            20,
            'fixture-model',
            '1',
            $appliedAt,
        ]);
    }

    ensureOmGenerationProjection($pdo, $runId, $idPrefix, $appliedAt);

    $pdo->exec(sprintf('INSERT INTO om_source_revision (id, revision) VALUES (1, %d)', $sourceRevision));
    $insertChange = $pdo->prepare('INSERT INTO om_source_change (kind, source_id, revision) VALUES (?, ?, ?)');
    $written = 0;
    for ($i = 1; $i <= $observationCount && $written < $sourceChangeCount; ++$i) {
        $insertChange->execute([MemoryKind::Observation->value, $canonicalIds ? ProjectSourceFixture::memoryId(MemoryKind::Observation, $i) : sprintf('%s-obs-%d', $idPrefix, $i), $i]);
        ++$written;
    }
    for ($i = 1; $i <= $reflectionCount && $written < $sourceChangeCount; ++$i) {
        $insertChange->execute([MemoryKind::Reflection->value, $canonicalIds ? ProjectSourceFixture::memoryId(MemoryKind::Reflection, $i) : sprintf('%s-ref-%d', $idPrefix, $i), $observationCount + $i]);
        ++$written;
    }
}

/**
 * Ensure generation tables exist and one active succeeded generation links the run's memories.
 * No-op when an active generation is already present. Never deletes or recreates the database.
 */
function ensureOmGenerationProjection(
    PDO $pdo,
    string $runId,
    string $idPrefix,
    string $appliedAt = '2026-01-01T00:00:00+00:00',
): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS om_memory_generation (
    generation_id TEXT PRIMARY KEY NOT NULL,
    run_id TEXT NOT NULL,
    status TEXT NOT NULL,
    observation_set_hash TEXT NOT NULL,
    reflector_model TEXT NOT NULL,
    reflector_schema_version TEXT NOT NULL,
    required_start_seq INTEGER,
    required_end_seq INTEGER,
    failure_code TEXT,
    created_at TEXT NOT NULL,
    completed_at TEXT
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS om_generation_reflection (
    generation_id TEXT NOT NULL,
    reflection_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    PRIMARY KEY (generation_id, reflection_id)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS om_generation_retained_observation (
    generation_id TEXT NOT NULL,
    observation_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    PRIMARY KEY (generation_id, observation_id)
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS om_active_generation (
    run_id TEXT PRIMARY KEY NOT NULL,
    generation_id TEXT NOT NULL
)
SQL);

    $active = $pdo->prepare('SELECT generation_id FROM om_active_generation WHERE run_id = ?');
    $active->execute([$runId]);
    if (false !== $active->fetchColumn()) {
        return;
    }

    $observationIds = $pdo->prepare('SELECT observation_id FROM om_observation WHERE run_id = ? ORDER BY source_end_seq, observation_id');
    $observationIds->execute([$runId]);
    /** @var list<string> $observations */
    $observations = array_map('strval', $observationIds->fetchAll(PDO::FETCH_COLUMN));

    $reflectionIds = $pdo->prepare('SELECT reflection_id FROM om_reflection WHERE run_id = ? ORDER BY created_at, reflection_id');
    $reflectionIds->execute([$runId]);
    /** @var list<string> $reflections */
    $reflections = array_map('strval', $reflectionIds->fetchAll(PDO::FETCH_COLUMN));

    if ([] === $observations && [] === $reflections) {
        return;
    }

    $generationId = sprintf('%s-gen-1', $idPrefix);
    $pdo->prepare(<<<'SQL'
INSERT INTO om_memory_generation (
    generation_id, run_id, status, observation_set_hash, reflector_model, reflector_schema_version,
    required_start_seq, required_end_seq, failure_code, created_at, completed_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
SQL)->execute([
        $generationId,
        $runId,
        'succeeded',
        hash('sha256', $idPrefix.'-active-set'),
        'fixture-model',
        '1',
        1,
        max(1, count($observations)),
        $appliedAt,
        $appliedAt,
    ]);

    $insertGenerationReflection = $pdo->prepare('INSERT INTO om_generation_reflection (generation_id, reflection_id, position) VALUES (?, ?, ?)');
    foreach ($reflections as $index => $reflectionId) {
        $insertGenerationReflection->execute([$generationId, $reflectionId, $index + 1]);
    }

    $insertRetained = $pdo->prepare('INSERT INTO om_generation_retained_observation (generation_id, observation_id, position) VALUES (?, ?, ?)');
    foreach ($observations as $index => $observationId) {
        $insertRetained->execute([$generationId, $observationId, $index + 1]);
    }

    $pdo->prepare('INSERT INTO om_active_generation (run_id, generation_id) VALUES (?, ?)')->execute([$runId, $generationId]);
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
    $process = new Process($command);
    $process->run();
    if (!$process->isSuccessful() && !$allowFailure) {
        throw new ProcessFailedException($process);
    }
}
