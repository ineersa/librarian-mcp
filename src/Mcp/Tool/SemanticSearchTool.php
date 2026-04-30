<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class SemanticSearchTool
{
    public function __construct(
        private ReadyLibraryResolver $readyLibraryResolver,
        private LibraryManager $libraryManager,
        private VeraCli $veraCli,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run semantic/hybrid search in a single ready library.
     *
     * @param string           $library Ready library slug, e.g. "symfony/symfony-docs@8.0"
     * @param string           $query   Search intent, e.g. "dashboard controller", "routing attribute", "crud actions"
     * @param string|null      $lang    Optional language filter (examples: "php", "js", "md"); null searches all languages
     * @param string|null      $path    Optional path glob filter (examples: "src/**", "doc/**"); null searches all paths
     * @param SymbolType|null  $type    Optional symbol-type filter
     * @param SearchScope|null $scope   Corpus scope filter: source, docs, runtime, or all
     * @param int              $limit   Max number of results to return (1..100)
     */
    #[McpTool(name: 'semantic-search', description: <<<'DESC'
        Run a semantic code search inside one ready library.
        Uses hybrid BM25 + vector similarity with optional reranking.
        By default, search is source-biased; use scope=docs for documentation-focused queries.
        Returns ranked code chunks with file path, line range, content, and symbol type.
        DESC)]
    public function search(
        string $library,
        string $query,
        ?string $lang = null,
        ?string $path = null,
        ?SymbolType $type = null,
        ?SearchScope $scope = null,
        int $limit = 20,
    ): CallToolResult {
        $startedAt = microtime(true);

        if ('' === trim($query)) {
            return $this->resultFactory->error(
                'Query must not be empty.',
                false,
                'Provide a semantic query describing the code you want to find (e.g. "routing controller", "dashboard config", "crud actions").',
            );
        }

        if (null !== $lang && '' === trim($lang)) {
            return $this->resultFactory->error(
                'Language filter must not be blank.',
                false,
                'Use a language value such as "php", "js", or "md", or omit `lang` entirely.',
            );
        }

        if (null !== $path && '' === trim($path)) {
            return $this->resultFactory->error(
                'Path filter must not be blank.',
                false,
                'Use a non-empty glob path such as "src/**" or "doc/**", or omit `path` entirely.',
            );
        }

        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $limit = max(1, min(100, $limit));

            $result = $this->veraCli->searchLibrary(
                $this->libraryManager->getAbsolutePath($target),
                $query,
                [
                    'lang' => null !== $lang ? trim($lang) : null,
                    'path' => null !== $path ? trim($path) : null,
                    'type' => $type?->value,
                    'scope' => $scope?->value,
                    'limit' => $limit,
                ],
            );

            $this->logger->info('MCP tool call', [
                'tool' => 'semantic-search',
                'library' => $library,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (ToolCallException $e) {
            return $this->resultFactory->error($e->getMessage(), $e->retryable, $e->hint);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'semantic-search',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => true,
            ]);

            return $this->resultFactory->error('Failed to query library.', true, 'Verify the library is indexed and try again.');
        }
    }
}
