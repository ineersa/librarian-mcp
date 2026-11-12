<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers Hatfield OM/session source locations without booting Hatfield.
 */
final class HatfieldSourceDiscovery
{
    private const string DEFAULT_SESSIONS_PATH = '.hatfield/sessions';

    private const string DEFAULT_OM_DATABASE_PATH = '.hatfield/extensions-data/observational-memory/om.sqlite';

    private readonly HatfieldSettingsOverlay $overlay;

    private readonly HatfieldPathResolver $pathResolver;

    private readonly ServicePathMapper $pathMapper;

    private readonly OmSourceReader $omSourceReader;

    /**
     * @param array<string, string> $pathMappings
     */
    public function __construct(
        private readonly string $hatfieldUserHome,
        private readonly string $hatfieldAppRoot,
        array $pathMappings = [],
        ?HatfieldSettingsOverlay $overlay = null,
        ?OmSourceReader $omSourceReader = null,
        ?ServicePathMapper $pathMapper = null,
        ?HatfieldPathResolver $pathResolver = null,
    ) {
        $this->overlay = $overlay ?? new HatfieldSettingsOverlay();
        $this->omSourceReader = $omSourceReader ?? new OmSourceReader();
        $this->pathMapper = $pathMapper ?? new ServicePathMapper($pathMappings);
        $this->pathResolver = $pathResolver ?? new HatfieldPathResolver($hatfieldUserHome, $hatfieldAppRoot);
    }

    public function discover(string $projectRoot): ProjectDiscoveryResult
    {
        $details = [
            'projectRoot' => $projectRoot,
            'omExtension' => OmExtensionIds::CLASS_NAME,
            'requiredSchemaVersions' => OmExtensionIds::REQUIRED_SCHEMA_VERSIONS,
        ];

        $logicalUserHome = trim($this->hatfieldUserHome);
        if ('' === $logicalUserHome) {
            return ProjectDiscoveryResult::error(
                'HATFIELD_USER_HOME is required to resolve Hatfield user settings.',
                issues: [$this->issue(ProjectSourceIssueCode::UserHomeUnavailable, 'HATFIELD_USER_HOME is empty or unavailable.')],
                details: $details,
            );
        }

        $mappedUserHome = $this->pathMapper->map($logicalUserHome);
        $details['logicalUserHome'] = $logicalUserHome;
        $mappedUserHome = $this->canonicalizeExistingPath($mappedUserHome);
        $details['userHome'] = $mappedUserHome;

        if (!is_dir($mappedUserHome) || !is_readable($mappedUserHome)) {
            return ProjectDiscoveryResult::error(
                'Configured Hatfield user home is unavailable in the service filesystem.',
                issues: [$this->issue(ProjectSourceIssueCode::UserHomeUnavailable, 'HATFIELD_USER_HOME is configured but unavailable after path mapping.')],
                details: $details,
            );
        }

        if (!is_dir($projectRoot) || !is_readable($projectRoot)) {
            return ProjectDiscoveryResult::error(
                'Registered project root is missing or unreadable.',
                issues: [$this->issue(ProjectSourceIssueCode::RootUnavailable, 'Project root directory is unavailable.')],
                details: $details,
            );
        }

        $hatfieldDir = rtrim($projectRoot, '/').'/.hatfield';
        if (!is_dir($hatfieldDir)) {
            return ProjectDiscoveryResult::error(
                'Project has no .hatfield directory.',
                issues: [$this->issue(ProjectSourceIssueCode::HatfieldMissing, 'Missing .hatfield directory under the registered project root.')],
                details: $details,
            );
        }

        try {
            $effective = $this->loadEffectiveSettings($projectRoot, $mappedUserHome, $details);
        } catch (ProjectSourceException $exception) {
            return ProjectDiscoveryResult::error(
                $exception->getMessage(),
                issues: [$this->issue($exception->issueCode, $exception->getMessage())],
                details: $details,
            );
        }

        if (!$this->isOmEnabled($effective)) {
            return ProjectDiscoveryResult::error(
                'Observational memory extension is not enabled for this project.',
                issues: [$this->issue(ProjectSourceIssueCode::OmDisabled, 'extensions.enabled does not include ObservationalMemoryExtension.')],
                details: [
                    ...$details,
                    'extensionsEnabled' => $this->enabledExtensions($effective),
                ],
            );
        }

        try {
            $logicalSessionsPath = $this->resolveSessionsPath($effective, $projectRoot);
            $logicalDatabasePath = $this->resolveOmDatabasePath($effective, $projectRoot);
        } catch (ProjectSourceException $exception) {
            return ProjectDiscoveryResult::error(
                $exception->getMessage(),
                issues: [$this->issue($exception->issueCode, $exception->getMessage())],
                details: $details,
            );
        }

        $sessionsPath = $this->canonicalizeExistingPath($this->pathMapper->map($logicalSessionsPath));
        $databasePath = $this->canonicalizeExistingPath($this->pathMapper->map($logicalDatabasePath));

        $details['logicalSessionsPath'] = $logicalSessionsPath;
        $details['logicalDatabasePath'] = $logicalDatabasePath;
        $details['sessionsPath'] = $sessionsPath;
        $details['databasePath'] = $databasePath;

        try {
            $sessionCount = $this->countSessions($sessionsPath);
            $om = $this->omSourceReader->inspect($databasePath);
        } catch (ProjectSourceException $exception) {
            return ProjectDiscoveryResult::error(
                $exception->getMessage(),
                issues: [$this->issue($exception->issueCode, $exception->getMessage())],
                details: $details,
                databasePath: $databasePath,
                sessionsPath: $sessionsPath,
                sessionCount: $sessionCount ?? 0,
            );
        }

        $details['appliedSchemaVersions'] = $om['appliedVersions'];

        return ProjectDiscoveryResult::ready(
            databasePath: $databasePath,
            sessionsPath: $sessionsPath,
            schemaVersion: $om['schemaVersion'],
            observationCount: $om['observationCount'],
            reflectionCount: $om['reflectionCount'],
            sessionCount: $sessionCount,
            sourceRevision: $om['sourceRevision'],
            sourceChangeCount: $om['sourceChangeCount'],
            details: $details,
        );
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function loadEffectiveSettings(string $projectRoot, string $mappedUserHome, array &$details): array
    {
        $logicalUserSettingsPath = rtrim($this->hatfieldUserHome, '/').'/.hatfield/settings.yaml';
        // Always resolve the service-visible settings path from the mapped home so
        // host-prefix mappings are honored before availability checks/reads.
        $userSettingsPath = $this->canonicalizeExistingPath(
            rtrim($mappedUserHome, '/').'/.hatfield/settings.yaml',
        );
        $projectSettingsPath = $this->canonicalizeExistingPath(
            rtrim($projectRoot, '/').'/.hatfield/settings.yaml',
        );

        $details['logicalUserSettingsPath'] = $logicalUserSettingsPath;
        $details['userSettingsPath'] = $userSettingsPath;
        $details['projectSettingsPath'] = $projectSettingsPath;
        $details['userSettingsPresent'] = is_file($userSettingsPath);
        $details['projectSettingsPresent'] = is_file($projectSettingsPath);

        $merged = [];
        $merged = $this->overlay->overlay($merged, $this->loadYamlFile($userSettingsPath));
        $merged = $this->overlay->overlay($merged, $this->loadYamlFile($projectSettingsPath));

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadYamlFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        if (!is_readable($path)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SettingsMalformed, \sprintf('Settings file is unreadable: %s', basename(\dirname($path)).'/'.basename($path)));
        }

        $content = file_get_contents($path);
        if (false === $content) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SettingsMalformed, 'Unable to read Hatfield settings YAML.');
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SettingsMalformed, 'Hatfield settings YAML is malformed.', previous: $exception);
        }

        if (null === $parsed) {
            return [];
        }

        if (!\is_array($parsed)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SettingsMalformed, 'Hatfield settings YAML must parse to a mapping.');
        }

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isOmEnabled(array $settings): bool
    {
        return \in_array(OmExtensionIds::CLASS_NAME, $this->enabledExtensions($settings), true);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<string>
     */
    private function enabledExtensions(array $settings): array
    {
        $extensions = $settings['extensions'] ?? null;
        if (!\is_array($extensions)) {
            return [];
        }

        $enabled = $extensions['enabled'] ?? null;
        if (!\is_array($enabled)) {
            return [];
        }

        $out = [];
        foreach ($enabled as $entry) {
            if (\is_string($entry) && '' !== trim($entry)) {
                $out[] = trim($entry);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveSessionsPath(array $settings, string $projectRoot): string
    {
        $sessions = $settings['sessions'] ?? null;
        $path = self::DEFAULT_SESSIONS_PATH;
        if (\is_array($sessions) && isset($sessions['path']) && \is_string($sessions['path']) && '' !== $sessions['path']) {
            $path = $sessions['path'];
        }

        if (str_contains($path, '%kernel.project_dir%') && '' === trim($this->hatfieldAppRoot)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::AppRootRequired, 'HATFIELD_APP_ROOT is required because sessions.path uses %kernel.project_dir%.');
        }

        return $this->pathResolver->resolve($path, $projectRoot);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveOmDatabasePath(array $settings, string $projectRoot): string
    {
        $path = self::DEFAULT_OM_DATABASE_PATH;
        $om = $settings['observational_memory'] ?? null;
        if (\is_array($om)) {
            $storage = $om['storage'] ?? null;
            if (\is_array($storage) && isset($storage['database']) && \is_string($storage['database']) && '' !== $storage['database']) {
                $path = $storage['database'];
            }
        }

        // OmPaths: absolute paths retained; relative paths joined to project CWD only.
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($projectRoot, '/').'/'.ltrim($path, '/');
    }

    private function countSessions(string $sessionsPath): int
    {
        if (!is_dir($sessionsPath)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SessionsUnavailable, 'Configured sessions directory is missing.');
        }

        if (!is_readable($sessionsPath)) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SessionsUnavailable, 'Configured sessions directory is unreadable.');
        }

        $entries = scandir($sessionsPath);
        if (false === $entries) {
            throw new ProjectSourceException(ProjectSourceIssueCode::SessionsUnavailable, 'Unable to list configured sessions directory.');
        }

        $count = 0;
        $unreadable = 0;
        foreach ($entries as $entry) {
            if (!preg_match('/^[1-9][0-9]*$/', $entry)) {
                continue;
            }

            $sessionDir = $sessionsPath.'/'.$entry;
            if (!is_dir($sessionDir)) {
                continue;
            }

            $events = $sessionDir.'/events.jsonl';
            if (!is_file($events)) {
                continue;
            }

            if (!is_readable($events)) {
                ++$unreadable;
                continue;
            }

            ++$count;
        }

        if ($unreadable > 0) {
            throw new ProjectSourceException(ProjectSourceIssueCode::JournalsUnreadable, \sprintf('%d session journal(s) are unreadable.', $unreadable));
        }

        return $count;
    }

    /**
     * Prefer realpath for existing filesystem entries so symlink destinations are reported.
     * Non-existent paths remain as mapped logical/service paths for actionable errors.
     */
    private function canonicalizeExistingPath(string $path): string
    {
        if ('' === $path) {
            return $path;
        }

        $resolved = realpath($path);

        return false === $resolved ? $path : $resolved;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function issue(ProjectSourceIssueCode $code, string $message): array
    {
        return [
            'code' => $code->value,
            'message' => $message,
        ];
    }
}
