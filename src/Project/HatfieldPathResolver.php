<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Filesystem\Path;

/**
 * Mirrors Hatfield SettingsPathResolver for sessions.path-style keys.
 *
 * Expands %kernel.project_dir% against hatfieldAppRoot and ~ against hatfieldUserHome,
 * then joins relative paths against the provided base directory.
 */
final class HatfieldPathResolver
{
    public function __construct(
        private readonly string $hatfieldUserHome,
        private readonly string $hatfieldAppRoot,
    ) {
    }

    public function resolve(string $path, string $baseDir): string
    {
        if ('' === $path) {
            return '';
        }

        $resolved = str_replace('%kernel.project_dir%', $this->hatfieldAppRoot, $path);

        if (str_starts_with($resolved, '~')) {
            $resolved = rtrim($this->hatfieldUserHome, '/').substr($resolved, 1);
        }

        return Path::makeAbsolute($resolved, $baseDir);
    }
}
