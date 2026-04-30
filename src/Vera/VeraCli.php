<?php

declare(strict_types=1);

namespace App\Vera;

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

        $args = [$this->veraBinary, 'index', $absolutePath];

        foreach ($config->excludePatterns as $pattern) {
            $args[] = '--exclude';
            $args[] = $pattern;
        }

        if ($config->noIgnore) {
            $args[] = '--no-ignore';
        }

        if ($config->noDefaultExcludes) {
            $args[] = '--no-default-excludes';
        }

        return $this->runCommand($args, timeout: 600);
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
     * @param array<string, mixed> $filters Optional filters (limit, lang, path)
     *
     * @return array<string, mixed>
     */
    public function grepLibrary(string $absolutePath, string $pattern, array $filters = []): array
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        $args = [$this->veraBinary, 'grep', $pattern, '--json'];

        foreach (['lang', 'path'] as $filterName) {
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
            throw new VeraCliException('vera grep returned invalid JSON');
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
     * Run a command with Symfony Process.
     *
     * @param array<string> $command
     *
     * @throws VeraCliException on failure
     */
    private function runCommand(array $command, ?int $timeout = null, ?string $workingDirectory = null): string
    {
        $process = new Process($command);
        $process->setTimeout($timeout ?? $this->processTimeout);

        if (null !== $workingDirectory) {
            $process->setWorkingDirectory($workingDirectory);
        }

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw VeraCliException::processError($command, $e->getMessage());
        }

        if (!$process->isSuccessful()) {
            $errorOutput = '' !== $process->getErrorOutput() ? $process->getErrorOutput() : $process->getOutput();
            throw VeraCliException::commandFailed($command, $process->getExitCode() ?? 1, $errorOutput);
        }

        return $process->getOutput();
    }
}
