<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Vera\VeraCli;
use App\Vera\VeraIndexingConfig;
use Psr\Log\LoggerInterface;

final readonly class LibraryMetadataCorpus
{
    public function __construct(
        private readonly VeraCli $veraCli,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function upsert(Library $library): void
    {
        $this->ensureCorpusDirectory();

        $path = $this->documentPath($library->getSlug());

        if (LibraryStatus::Ready !== $library->getStatus()) {
            if (is_file($path)) {
                unlink($path);
                $this->reindex();
            }

            return;
        }

        $lastIndexedAt = $library->getLastIndexedAt()?->format(\DATE_ATOM) ?? 'null';

        $content = <<<TXT
slug: {$library->getSlug()}
name: {$library->getName()}
git_url: {$library->getGitUrl()}
branch: {$library->getBranch()}
last_indexed_at: {$lastIndexedAt}

description:
{$library->getDescription()}
TXT;

        file_put_contents($path, $content);

        $this->reindex();
    }

    public function remove(Library $library): void
    {
        $path = $this->documentPath($library->getSlug());
        if (is_file($path)) {
            unlink($path);
            $this->reindex();
        }
    }

    /** @return array<string, string> slug => reason */
    public function search(string $query, int $limit): array
    {
        $root = $this->rootPath();
        if (!is_dir($root)) {
            return [];
        }

        try {
            $raw = $this->veraCli->searchLibrary($root, $query, ['limit' => $limit]);
        } catch (\Throwable $e) {
            $this->logger->warning('Metadata corpus search failed', ['error' => $e->getMessage()]);

            return [];
        }

        $results = $raw['results'] ?? $raw;
        if (!\is_array($results)) {
            return [];
        }

        $slugs = [];
        foreach ($results as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $path = $result['path'] ?? $result['file'] ?? null;
            if (!\is_string($path)) {
                continue;
            }

            $slug = pathinfo($path, \PATHINFO_FILENAME);
            if ('' === $slug) {
                continue;
            }

            $slugs[$slug] = 'semantic metadata match';

            if (\count($slugs) >= $limit) {
                break;
            }
        }

        return $slugs;
    }

    public function rootPath(): string
    {
        return rtrim($this->projectDir, '/').'/data/mcp-metadata-corpus';
    }

    private function ensureCorpusDirectory(): void
    {
        $root = $this->rootPath();
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }
    }

    private function documentPath(string $slug): string
    {
        return $this->rootPath().'/'.$slug.'.txt';
    }

    private function reindex(): void
    {
        try {
            $this->veraCli->indexLibrary($this->rootPath(), new VeraIndexingConfig());
        } catch (\Throwable $e) {
            $this->logger->warning('Metadata corpus indexing failed', ['error' => $e->getMessage()]);
        }
    }
}
