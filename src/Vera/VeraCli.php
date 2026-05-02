<?php

declare(strict_types=1);

namespace App\Vera;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps the vera CLI binary for safe invocation from Symfony.
 *
 * Pure CLI wrapper — receives resolved absolute paths, no entity/slug awareness.
 * LibraryManager resolves paths and passes them in.
 */
class VeraCli
{
    private string $veraBinary;
    private string $gitBinary;
    private int $processTimeout;

    public function __construct(
        ?string $veraBinary = null,
        int $processTimeout = 300,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->veraBinary = $veraBinary ?? 'vera';
        $this->gitBinary = 'git';
        $this->processTimeout = $processTimeout;
    }

    /**
     * Clone a git repository to the given absolute path.
     *
     * @return string stdout from git
     *
     * @throws VeraCliException on failure
     */
    public function cloneRepository(string $absolutePath, string $gitUrl, string $branch): string
    {
        return $this->runCommand([
            $this->gitBinary, 'clone',
            '--branch', $branch,
            $gitUrl,
            $absolutePath,
        ]);
    }

    /**
     * Run `vera index` on a cloned repository.
     *
     * @throws VeraCliException on failure
     */
    public function indexLibrary(string $absolutePath, VeraIndexingConfig $config): string
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        $args = [$this->veraBinary, 'index', '.'];

        foreach ($config->excludePatterns as $pattern) {
            $args[] = '--exclude';
            $args[] = $pattern;
        }

        $args[] = '--no-ignore';

        if ($config->noDefaultExcludes) {
            $args[] = '--no-default-excludes';
        }

        return $this->runCommand($args, timeout: 600, workingDirectory: $absolutePath);
    }

    /**
     * Run `vera search` on an indexed repository and return decoded JSON.
     *
     * @param array<string, mixed> $filters Optional filters (limit, etc.)
     *
     * @return array<string, mixed> Decoded search results
     *
     * @throws VeraCliException on failure
     */
    public function searchLibrary(string $absolutePath, string $query, array $filters = []): array
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        $args = [$this->veraBinary, 'search', $query, '--json'];

        foreach (['lang', 'path', 'type', 'scope'] as $filterName) {
            if (isset($filters[$filterName]) && \is_string($filters[$filterName]) && '' !== trim($filters[$filterName])) {
                $args[] = '--'.$filterName;
                $args[] = trim($filters[$filterName]);
            }
        }

        if (isset($filters['limit']) && \is_int($filters['limit'])) {
            $args[] = '--limit';
            $args[] = (string) $filters['limit'];
        }

        $stdout = $this->runCommand($args, workingDirectory: $absolutePath);

        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new VeraCliException('vera search returned invalid JSON');
        }

        return $decoded;
    }

    /**
     * Run `vera grep` in an indexed repository and return decoded JSON.
     *
     * @param array<string, mixed> $filters Optional filters (limit, ignoreCase, context, scope)
     *
     * @return array<string, mixed>
     */
    public function grepLibrary(string $absolutePath, string $pattern, array $filters = []): array
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        $args = [$this->veraBinary, 'grep', $pattern, '--json'];

        if (true === ($filters['ignoreCase'] ?? false)) {
            $args[] = '--ignore-case';
        }

        if (isset($filters['context']) && \is_int($filters['context'])) {
            $args[] = '--context';
            $args[] = (string) $filters['context'];
        }

        if (isset($filters['scope']) && \is_string($filters['scope']) && '' !== trim($filters['scope'])) {
            $args[] = '--scope';
            $args[] = trim($filters['scope']);
        }

        if (isset($filters['limit']) && \is_int($filters['limit'])) {
            $args[] = '--limit';
            $args[] = (string) $filters['limit'];
        }

        $stdout = $this->runCommand($args, workingDirectory: $absolutePath);

        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new VeraCliException('vera grep returned invalid JSON');
        }

        return $decoded;
    }

    /**
     * Run `vera overview` on an indexed repository and return decoded JSON.
     *
     * @return array<string, mixed>
     */
    public function overviewLibrary(string $absolutePath): array
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        $stdout = $this->runCommand([
            $this->veraBinary,
            'overview',
            '--json',
        ], workingDirectory: $absolutePath);

        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new VeraCliException('vera overview returned invalid JSON');
        }

        return $decoded;
    }

    /**
     * Check if vera is available and working.
     */
    public function isAvailable(): bool
    {
        try {
            $this->runCommand([$this->veraBinary, '--version']);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get vera version string.
     */
    public function getVersion(): string
    {
        return trim($this->runCommand([$this->veraBinary, '--version']));
    }

    /**
     * Get full vera config as decoded JSON.
     *
     * @return array<string, mixed>
     *
     * @throws VeraCliException on failure or invalid JSON
     */
    public function getConfig(): array
    {
        $stdout = $this->runCommand([$this->veraBinary, 'config', '--json']);

        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new VeraCliException('vera config --json returned invalid JSON');
        }

        return $decoded;
    }

    /**
     * Read indexing.default_excludes from `vera config --json`.
     *
     * @return array<string>
     *
     * @throws VeraCliException on failure or invalid config shape
     */
    public function getIndexingDefaultExcludes(): array
    {
        $config = $this->getConfig();

        $defaultExcludes = $config['indexing']['default_excludes'] ?? null;
        if (!\is_array($defaultExcludes)) {
            throw new VeraCliException('vera config --json is missing indexing.default_excludes');
        }

        $normalized = [];
        foreach ($defaultExcludes as $pattern) {
            if (!\is_string($pattern)) {
                throw new VeraCliException('vera config --json returned non-string value in indexing.default_excludes');
            }
            $normalized[] = $pattern;
        }

        return $normalized;
    }

    /**
     * Run a command with Symfony Process.
     *
     * @param array<string> $command
     *
     * @throws VeraCliException on failure
     */
    private function runCommand(array $command, ?int $timeout = null, ?string $workingDirectory = null): string
    {
        $effectiveTimeout = $timeout ?? $this->processTimeout;
        $originalCommand = $command;
        $startedAt = microtime(true);

        $this->logger?->info('Vera CLI command starting.', [
            'command' => $originalCommand,
            'workingDirectory' => $workingDirectory,
            'timeoutSeconds' => $effectiveTimeout,
        ]);

        if (null !== $workingDirectory) {
            // Wrap in shell to guarantee vera runs from the target directory.
            // Some vera operations discover .gitignore/.vera from CWD,
            // and must literally be cd'd into before execution.
            $shellCmd = implode(' ', array_map('escapeshellarg', $command));
            $command = ['bash', '-c', \sprintf(
                'cd %s && %s',
                escapeshellarg($workingDirectory),
                $shellCmd,
            )];
        }

        $process = new Process($command);
        $process->setTimeout($effectiveTimeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger?->error('Vera CLI process error.', [
                'command' => $originalCommand,
                'workingDirectory' => $workingDirectory,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            throw VeraCliException::processError($command, $e->getMessage());
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$process->isSuccessful()) {
            $errorOutput = '' !== $process->getErrorOutput() ? $process->getErrorOutput() : $process->getOutput();

            $this->logger?->error('Vera CLI command failed.', [
                'command' => $originalCommand,
                'workingDirectory' => $workingDirectory,
                'durationMs' => $durationMs,
                'exitCode' => $process->getExitCode() ?? 1,
                'errorOutput' => mb_substr($errorOutput, 0, 4000),
            ]);

            throw VeraCliException::commandFailed($command, $process->getExitCode() ?? 1, $errorOutput);
        }

        $output = $process->getOutput();

        $this->logger?->info('Vera CLI command finished.', [
            'command' => $originalCommand,
            'workingDirectory' => $workingDirectory,
            'durationMs' => $durationMs,
            'outputPreview' => mb_substr($output, 0, 1000),
        ]);

        return $output;
    }
}
