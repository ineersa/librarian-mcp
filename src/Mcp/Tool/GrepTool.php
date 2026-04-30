<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class GrepTool
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
     * Run regex grep over indexed files in a single ready library.
     *
     * @param string           $library    Ready library slug, e.g. "easycorp/easyadminbundle@5.x"
     * @param string           $pattern    Regex pattern (vera/Rust regex syntax), e.g. "AbstractCrudController" or "TODO|FIXME"
     * @param bool             $ignoreCase True for case-insensitive matching
     * @param int              $context    Context lines before/after each match (0..20)
     * @param SearchScope|null $scope      Corpus scope filter: source, docs, runtime, or all
     * @param int              $limit      Max number of results to return (1..100)
     */
    #[McpTool(name: 'grep', description: <<<'DESC'
        Run a regex pattern search inside one ready library.
        Searches indexed file contents with surrounding context lines.
        Searches only files included in the current index state/exclusions.
        Returns matches with file path, line range, and content.
        DESC)]
    public function grep(
        string $library,
        string $pattern,
        bool $ignoreCase = false,
        int $context = 2,
        ?SearchScope $scope = null,
        int $limit = 20,
    ): CallToolResult {
        $startedAt = microtime(true);

        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $limit = max(1, min(100, $limit));
            $context = max(0, min(20, $context));

            $result = $this->veraCli->grepLibrary(
                $this->libraryManager->getAbsolutePath($target),
                $pattern,
                [
                    'ignoreCase' => $ignoreCase,
                    'context' => $context,
                    'scope' => $scope?->value,
                    'limit' => $limit,
                ],
            );

            $this->logger->info('MCP tool call', [
                'tool' => 'grep',
                'library' => $library,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (ToolCallException $e) {
            return $this->resultFactory->error($e->getMessage(), $e->retryable, $e->hint);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'grep',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => true,
            ]);

            return $this->resultFactory->error('Failed to grep library.', true, 'Verify regex/pattern and library index state, then retry.');
        }
    }
}
