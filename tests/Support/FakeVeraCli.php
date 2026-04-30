<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Vera\VeraCli;
use App\Vera\VeraCliException;
use App\Vera\VeraIndexingConfig;

final class FakeVeraCli extends VeraCli
{
    public function cloneRepository(string $absolutePath, string $gitUrl, string $branch): string
    {
        if (str_contains($gitUrl, 'nonexistent/repo-that-does-not-exist-xyz')) {
            throw VeraCliException::commandFailed(['git', 'clone', '--branch', $branch, $gitUrl, $absolutePath], 128, 'git: repository not found');
        }

        if (!is_dir($absolutePath)) {
            mkdir($absolutePath, 0755, true);
        }

        if (!is_dir($absolutePath.'/.git')) {
            mkdir($absolutePath.'/.git', 0755, true);
        }

        if (!is_dir($absolutePath.'/src')) {
            mkdir($absolutePath.'/src', 0755, true);
        }

        file_put_contents($absolutePath.'/README.md', "# Fixture\n\nbranch: {$branch}\n");
        file_put_contents($absolutePath.'/src/Sample.php', "<?php\n\nfinal class Sample {}\n");

        return 'cloned';
    }

    public function indexLibrary(string $absolutePath, VeraIndexingConfig $config): string
    {
        if (!is_dir($absolutePath)) {
            throw VeraCliException::notCloned($absolutePath);
        }

        return 'indexed';
    }

    public function searchLibrary(string $absolutePath, string $query, array $filters = []): array
    {
        return ['results' => []];
    }

    public function grepLibrary(string $absolutePath, string $pattern, array $filters = []): array
    {
        return ['results' => []];
    }
}
