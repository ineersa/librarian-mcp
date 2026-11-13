<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Maps logical host absolute paths onto service-visible mounted prefixes.
 *
 * Longest matching host prefix wins. Relative paths are returned unchanged.
 */
final class ServicePathMapper
{
    /**
     * @param array<string, string> $pathMappings host-prefix => service-prefix
     */
    public function __construct(
        private readonly array $pathMappings = [],
    ) {
    }

    /** Resolve parent traversal in the logical namespace, not the mount layout. */
    public function mapSourcePath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            return $path;
        }
        $parts = explode('/', $path);
        if (!\in_array('..', $parts, true)) {
            return $this->map(Path::canonicalize($path));
        }

        $logical = '/';
        foreach ($parts as $index => $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                $logical = Path::getDirectory($logical);
                continue;
            }

            $logical = Path::join($logical, $part);
            $remaining = \array_slice($parts, $index + 1);
            if (!\in_array('..', $remaining, true)) {
                return $this->map(Path::canonicalize($logical.'/'.implode('/', $remaining)));
            }

            $service = $this->map($logical);
            if (is_link($service)) {
                $resolved = realpath($service);
                if (false === $resolved || !is_dir($resolved)) {
                    throw new ProjectSourceException(ProjectSourceIssueCode::PathMappingUnavailable, 'Cannot resolve a source symlink before parent traversal. Mount its target read-only or configure a resolved absolute source path.');
                }
                $logical = $this->logicalSymlinkTarget($resolved, $service === $logical);
            } elseif (!is_dir($service) && ($service !== $logical || $this->map($path) === $path)) {
                throw new ProjectSourceException(ProjectSourceIssueCode::PathMappingUnavailable, 'Cannot resolve parent traversal through an unavailable source directory. Check its read-only mount or configure a resolved absolute source path.');
            }
        }

        return $this->map($logical);
    }

    public function map(string $path): string
    {
        if ('' === $path || !str_starts_with($path, '/')) {
            return $path;
        }

        $bestHost = null;
        $bestService = null;
        $bestLength = -1;

        foreach ($this->pathMappings as $hostPrefix => $servicePrefix) {
            if (!\is_string($hostPrefix) || !\is_string($servicePrefix) || '' === $hostPrefix) {
                continue;
            }

            $normalizedHost = rtrim($hostPrefix, '/');
            if ('' === $normalizedHost) {
                $normalizedHost = '/';
            }
            $normalizedService = rtrim($servicePrefix, '/');
            $normalizedService = '' === $normalizedService ? '/' : $normalizedService;

            if ('/' === $normalizedHost) {
                if (\strlen($normalizedHost) > $bestLength) {
                    $bestHost = $normalizedHost;
                    $bestService = $normalizedService;
                    $bestLength = \strlen($normalizedHost);
                }
                continue;
            }

            if ($path === $normalizedHost || str_starts_with($path, $normalizedHost.'/')) {
                if (\strlen($normalizedHost) > $bestLength) {
                    $bestHost = $normalizedHost;
                    $bestService = $normalizedService;
                    $bestLength = \strlen($normalizedHost);
                }
            }
        }

        if (null === $bestHost || null === $bestService) {
            return $path;
        }

        if ('/' === $bestHost) {
            return ('/' === $bestService ? '' : $bestService).$path;
        }

        $suffix = substr($path, \strlen($bestHost));

        return ('/' === $bestService ? '' : $bestService).$suffix;
    }

    private function logicalSymlinkTarget(string $resolved, bool $unmappedSource): string
    {
        $candidates = [];
        $bestLength = -1;
        foreach ($this->pathMappings as $host => $service) {
            if ('' === $host || !Path::isAbsolute($host) || !Path::isAbsolute($service)) {
                continue;
            }
            $resolvedService = realpath($service);
            $service = false === $resolvedService ? Path::canonicalize($service) : $resolvedService;
            if (!Path::isBasePath($service, $resolved) || \strlen($service) < $bestLength) {
                continue;
            }
            if (\strlen($service) > $bestLength) {
                $candidates = [];
                $bestLength = \strlen($service);
            }
            $candidate = Path::makeAbsolute(Path::makeRelative($resolved, $service), $host);
            $candidates[$candidate] = $candidate;
        }

        if (1 === \count($candidates)) {
            return array_values($candidates)[0];
        }
        if ([] === $candidates && $unmappedSource && $this->map($resolved) === $resolved) {
            return $resolved;
        }

        throw new ProjectSourceException(ProjectSourceIssueCode::PathMappingUnavailable, 'A source symlink before parent traversal needs an unambiguous host-to-service mapping for its target. Configure that mapping or a resolved absolute source path.');
    }
}
