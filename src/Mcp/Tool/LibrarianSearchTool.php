<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Library;
use App\Mcp\LibraryMetadataCorpus;
use App\Repository\LibraryRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class LibrarianSearchTool
{
    public function __construct(
        private LibraryRepository $libraryRepository,
        private LibraryMetadataCorpus $metadataCorpus,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(name: 'librarian-search', description: 'Find relevant ready libraries')]
    public function search(string $query, int $topn = 10): CallToolResult
    {
        $startedAt = microtime(true);
        $topn = max(1, min(50, $topn));

        try {
            $databaseMatches = $this->libraryRepository->findReadyByMetadataLike($query, $topn);
            $semanticSlugMatches = $this->metadataCorpus->search($query, $topn);
            $semanticLibraries = $this->libraryRepository->findReadyBySlugs(array_keys($semanticSlugMatches));

            /** @var array<string, array{library: Library, score: int, matchReason: string}> $ranked */
            $ranked = [];

            foreach ($databaseMatches as $library) {
                $score = $this->scoreDbMatch($library, $query);
                $ranked[$library->getSlug()] = [
                    'library' => $library,
                    'score' => $score,
                    'matchReason' => 'metadata partial match',
                ];
            }

            foreach ($semanticLibraries as $library) {
                $slug = $library->getSlug();
                if (isset($ranked[$slug])) {
                    $ranked[$slug]['score'] += 25;
                    $ranked[$slug]['matchReason'] = 'metadata partial + semantic match';

                    continue;
                }

                $ranked[$slug] = [
                    'library' => $library,
                    'score' => 60,
                    'matchReason' => $semanticSlugMatches[$slug] ?? 'semantic metadata match',
                ];
            }

            uasort($ranked, static function (array $a, array $b): int {
                $scoreComparison = $b['score'] <=> $a['score'];
                if (0 !== $scoreComparison) {
                    return $scoreComparison;
                }

                return strcmp($a['library']->getSlug(), $b['library']->getSlug());
            });

            $result = [];
            foreach (\array_slice($ranked, 0, $topn, true) as $entry) {
                $library = $entry['library'];
                $result[] = [
                    'slug' => $library->getSlug(),
                    'description' => $library->getDescription(),
                    'gitUrl' => $library->getGitUrl(),
                    'lastIndexedAt' => $library->getLastIndexedAt()?->format(\DATE_ATOM),
                    'matchReason' => $entry['matchReason'],
                ];
            }

            $this->logger->info('MCP tool call', [
                'tool' => 'librarian-search',
                'query' => $query,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'librarian-search',
                'error' => $e->getMessage(),
                'retryable' => true,
            ]);

            return $this->resultFactory->error('Failed to search libraries.', true, 'Try again, then verify metadata corpus indexing.');
        }
    }

    private function scoreDbMatch(Library $library, string $query): int
    {
        $needle = mb_strtolower(trim($query));
        $slug = mb_strtolower($library->getSlug());
        $name = mb_strtolower($library->getName());
        $description = mb_strtolower($library->getDescription());
        $gitUrl = mb_strtolower($library->getGitUrl());

        $score = 0;
        if ($slug === $needle) {
            $score += 120;
        }
        if (str_contains($slug, $needle)) {
            $score += 50;
        }
        if (str_contains($name, $needle)) {
            $score += 40;
        }
        if (str_contains($description, $needle)) {
            $score += 30;
        }
        if (str_contains($gitUrl, $needle)) {
            $score += 20;
        }

        return $score;
    }
}
