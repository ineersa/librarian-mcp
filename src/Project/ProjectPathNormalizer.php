<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Normalizes project names and selected roots for identity/uniqueness.
 */
final class ProjectPathNormalizer
{
    public function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed);
    }

    public function canonicalizeRoot(string $rootPath): ?string
    {
        $trimmed = trim($rootPath);
        if ('' === $trimmed || !Path::isAbsolute($trimmed)) {
            return null;
        }

        $resolved = realpath($trimmed);
        if (false === $resolved || !is_dir($resolved)) {
            return null;
        }

        return $resolved;
    }
}
