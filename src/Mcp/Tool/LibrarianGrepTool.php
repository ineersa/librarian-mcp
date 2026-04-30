<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class LibrarianGrepTool
{
    public function __construct(
        private ReadyLibraryResolver $readyLibraryResolver,
        private LibraryManager $libraryManager,
        private VeraCli $veraCli,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(name: 'librarian-grep', description: 'Run regex grep in one ready library')]
    public function grep(
        string $library,
        string $pattern,
        ?string $path = null,
        ?string $lang = null,
        int $limit = 20,
    ): CallToolResult {
        $startedAt = microtime(true);

        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $limit = max(1, min(100, $limit));

            $result = $this->veraCli->grepLibrary(
                $this->libraryManager->getAbsolutePath($target),
                $pattern,
                [
                    'path' => $path,
                    'lang' => $lang,
                    'limit' => $limit,
                ],
            );

            $this->logger->info('MCP tool call', [
                'tool' => 'librarian-grep',
                'library' => $library,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (ToolCallException $e) {
            return $this->resultFactory->error($e->getMessage(), $e->retryable, $e->hint);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'librarian-grep',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => true,
            ]);

            return $this->resultFactory->error('Failed to grep library.', true, 'Verify regex/pattern and library index state, then retry.');
        }
    }
}
