<?php

declare(strict_types=1);

namespace App\Vera;

use Symfony\Component\Process\Process;

/**
 * Wraps the vera CLI binary for safe invocation from Symfony.
 *
 * All commands run through Symfony Process with timeouts, structured error
 * handling, and path safety (no arbitrary user-supplied paths).
 */
class VeraCli
{
    private string $veraBinary;
    private string $gitBinary;
    private string $librariesDir;
    private int $processTimeout;

    public function __construct(
        string $projectDir,
        ?string $veraBinary = null,
        int $processTimeout = 300,
    ) {
        $this->veraBinary = $veraBinary ?? 'vera';
        $this->gitBinary = 'git';
        $this->librariesDir = rtrim($projectDir, '/').'/data/libraries';
        $this->processTimeout = $processTimeout;
    }

    /**
     * Resolve the local repo path for a given slug.
     *
     * This is the only path ever passed to vera/git — no raw user input.
     */
    public function getRepoPath(string $slug): string
    {
        // Slug must be safe: lowercase alphanumeric + dashes only
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new \InvalidArgumentException(\sprintf('Invalid library slug: "%s". Use lowercase alphanumeric with dashes.', $slug));
        }

        return $this->librariesDir.'/'.$slug.'/repo';
    }

    /**
     * Clone a git repository into the libraries directory.
     *
     * @return string stdout from git
     *
     * @throws VeraCliException on failure
     */
    public function cloneRepository(string $slug, string $repositoryUrl, ?string $branch = null): string
    {
        $repoPath = $this->getRepoPath($slug);

        if (is_dir($repoPath.'/.git')) {
            throw VeraCliException::alreadyCloned($slug);
        }

        // Ensure parent directory exists
        $parentDir = \dirname($repoPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0777, true);
        }

        $args = [$this->gitBinary, 'clone'];
        if (null !== $branch) {
            $args[] = '--branch';
            $args[] = $branch;
        }
        $args[] = $repositoryUrl;
        $args[] = $repoPath;

        return $this->runCommand($args);
    }

    /**
     * Run `vera index` on a cloned repository.
     *
     * @throws VeraCliException on failure
     */
    public function indexLibrary(string $slug): string
    {
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) {
            throw VeraCliException::notCloned($slug);
        }

        return $this->runCommand(
            [$this->veraBinary, 'index', $repoPath],
            timeout: 600, // indexing can be slow for large repos
        );
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
    public function searchLibrary(string $slug, string $query, array $filters = []): array
    {
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) {
            throw VeraCliException::notCloned($slug);
        }

        $args = [$this->veraBinary, 'search', $query, '--json'];

        if (isset($filters['limit']) && \is_int($filters['limit'])) {
            $args[] = '--limit';
            $args[] = (string) $filters['limit'];
        }

        $stdout = $this->runCommand($args, workingDirectory: $repoPath);

        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new VeraCliException('vera search returned invalid JSON');
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
            throw VeraCliException::commandFailed($command, $process->getExitCode() ?? 1, $process->getErrorOutput() ?: $process->getOutput());
        }

        return $process->getOutput();
    }
}
