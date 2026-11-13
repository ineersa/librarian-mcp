<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Detects and normalizes Git repository origins for uniqueness checks.
 *
 * Uses argv Process only (no shell). Never clones, fetches, or runs hooks.
 */
final class GitOriginNormalizer
{
    private const int PROCESS_TIMEOUT_SECONDS = 5;
    private const int NOT_A_REPOSITORY_EXIT_CODE = 128;
    private const int CONFIG_NOT_FOUND_EXIT_CODE = 1;
    private const string METADATA_ENTRY = '.git';
    private const string INSPECTION_ERROR = 'Unable to inspect Git metadata. For mounted worktrees, mount the worktree and common Git metadata read-only at the paths recorded in .git.';

    public function detectNormalizedOrigin(string $canonicalRoot): ?string
    {
        if (!$this->isGitCheckout($canonicalRoot)) {
            return null;
        }

        $process = $this->runGit($canonicalRoot, ['config', '--get', 'remote.origin.url']);
        if (!$process->isSuccessful() && self::CONFIG_NOT_FOUND_EXIT_CODE !== $process->getExitCode()) {
            throw new ProjectSourceException(ProjectSourceIssueCode::GitMetadataUnavailable, self::INSPECTION_ERROR);
        }
        $origin = trim($process->getOutput());
        if ('' === $origin) {
            return null;
        }

        return $this->normalize($origin);
    }

    public function isGitCheckout(string $canonicalRoot): bool
    {
        $process = $this->runGit($canonicalRoot, ['rev-parse', '--git-dir']);
        if ($process->isSuccessful()) {
            return true;
        }
        if (self::NOT_A_REPOSITORY_EXIT_CODE === $process->getExitCode() && !$this->hasGitMetadata($canonicalRoot)) {
            return false;
        }

        throw new ProjectSourceException(ProjectSourceIssueCode::GitMetadataUnavailable, self::INSPECTION_ERROR);
    }

    public function normalize(string $rawOrigin): string
    {
        $origin = trim($rawOrigin);
        if ('' === $origin) {
            return '';
        }

        if (str_starts_with($origin, 'git@')) {
            // git@host:owner/repo(.git)
            $withoutPrefix = substr($origin, 4);
            $parts = explode(':', $withoutPrefix, 2);
            if (2 === \count($parts)) {
                $host = strtolower($parts[0]);
                $path = $this->normalizePath($parts[1]);

                return $host.'/'.$path;
            }
        }

        if (preg_match('#^ssh://([^@]+@)?([^/]+)/(.+)$#i', $origin, $matches)) {
            $host = strtolower($matches[2]);
            $path = $this->normalizePath($matches[3]);

            return $host.'/'.$path;
        }

        if (preg_match('#^(https?|git)://([^/]+)/(.+)$#i', $origin, $matches)) {
            $host = strtolower($matches[2]);
            // Strip userinfo credentials if present in host authority.
            if (str_contains($host, '@')) {
                $host = substr($host, strrpos($host, '@') + 1);
            }
            $path = $this->normalizePath($matches[3]);

            return $host.'/'.$path;
        }

        // Fallback: strip credentials-looking prefixes and trailing .git, lowercase.
        $origin = preg_replace('#^[^@]+@#', '', $origin) ?? $origin;

        return strtolower($this->normalizePath($origin));
    }

    private function hasGitMetadata(string $directory): bool
    {
        while (true) {
            $metadata = $directory.'/'.self::METADATA_ENTRY;
            if (file_exists($metadata) || is_link($metadata)) {
                return true;
            }
            $parent = Path::getDirectory($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = rawurldecode($path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (str_ends_with(strtolower($path), '.git')) {
            $path = substr($path, 0, -4);
        }

        return strtolower(rtrim($path, '/'));
    }

    /**
     * @param list<string> $arguments
     */
    private function runGit(string $cwd, array $arguments): Process
    {
        // Inspect only the explicitly selected checkout, including host-owned RO mounts.
        // Keep this permission per command; never modify Git's global safe.directory.
        $process = new Process(['git', '-c', 'safe.directory='.$cwd, ...$arguments], $cwd, null, null, self::PROCESS_TIMEOUT_SECONDS);
        try {
            $process->run();
        } catch (ExceptionInterface $exception) {
            throw new ProjectSourceException(ProjectSourceIssueCode::GitMetadataUnavailable, self::INSPECTION_ERROR, $exception);
        }

        return $process;
    }
}
