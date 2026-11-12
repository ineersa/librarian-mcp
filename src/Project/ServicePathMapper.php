<?php

declare(strict_types=1);

namespace App\Project;

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
}
