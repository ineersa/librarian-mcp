<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Library;
use App\Mcp\LibraryMetadataCorpus;
use App\Repository\LibraryRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class SearchLibrariesTool
{
    public function __construct(
        private LibraryRepository $libraryRepository,
        private LibraryMetadataCorpus $metadataCorpus,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Find ready libraries by metadata/semantic relevance.
     *
     * @param string $query Natural-language query (e.g. "symfony docs", "easyadmin", "react router")
     * @param int    $limit Max number of libraries to return (1..50)
     */
    #[McpTool(name: 'search-libraries', description: <<<'DESC'
        Find libraries in the catalog that match a query.
        Returns ready libraries ranked by relevance (DB metadata + semantic match).
        Each result includes slug, description, git URL, last indexed time, and match reason.
        Use the returned slug as the `library` parameter for semantic-search, grep, and read.
        Returns [] when no libraries match.
        DESC)]
    public function search(string $query, int $limit = 10): CallToolResult
    {
        $startedAt = microtime(true);

        if ('' === trim($query)) {
            return $this->resultFactory->error(
                'Query must not be empty.',
                false,
                'Provide a search term describing the library you need (e.g. "symfony", "react component", "orm").',
            );
        }

        $limit = max(1, min(50, $limit));

        try {
            $databaseMatches = $this->libraryRepository->findReadyByMetadataLike($query, $limit);
            $semanticSlugMatches = $this->metadataCorpus->search($query, $limit);
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
            foreach (\array_slice($ranked, 0, $limit, true) as $entry) {
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
                'tool' => 'search-libraries',
                'query' => $query,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'search-libraries',
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
