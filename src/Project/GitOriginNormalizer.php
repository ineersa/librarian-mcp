<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Process\Process;

/**
 * Detects and normalizes Git repository origins for uniqueness checks.
 *
 * Uses argv Process only (no shell). Never clones, fetches, or runs hooks.
 */
final class GitOriginNormalizer
{
    private const int PROCESS_TIMEOUT_SECONDS = 5;

    public function detectNormalizedOrigin(string $canonicalRoot): ?string
    {
        if (!$this->isGitCheckout($canonicalRoot)) {
            return null;
        }

        $origin = $this->runGit($canonicalRoot, ['config', '--get', 'remote.origin.url']);
        if (null === $origin || '' === $origin) {
            return null;
        }

        return $this->normalize($origin);
    }

    public function isGitCheckout(string $canonicalRoot): bool
    {
        $gitDir = $this->runGit($canonicalRoot, ['rev-parse', '--git-dir']);

        return null !== $gitDir && '' !== $gitDir;
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
    private function runGit(string $cwd, array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $cwd, null, null, self::PROCESS_TIMEOUT_SECONDS);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }
}
