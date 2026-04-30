<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class LibrarianQueryTool
{
    public function __construct(
        private ReadyLibraryResolver $readyLibraryResolver,
        private LibraryManager $libraryManager,
        private VeraCli $veraCli,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(name: 'librarian-query', description: 'Run semantic query in one ready library')]
    public function query(
        string $library,
        string $query,
        ?string $lang = null,
        ?string $path = null,
        ?string $type = null,
        ?string $scope = null,
        int $limit = 20,
    ): CallToolResult {
        $startedAt = microtime(true);

        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $limit = max(1, min(100, $limit));

            $result = $this->veraCli->searchLibrary(
                $this->libraryManager->getAbsolutePath($target),
                $query,
                [
                    'lang' => $lang,
                    'path' => $path,
                    'type' => $type,
                    'scope' => $scope,
                    'limit' => $limit,
                ],
            );

            $this->logger->info('MCP tool call', [
                'tool' => 'librarian-query',
                'library' => $library,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->resultFactory->success($result);
        } catch (ToolCallException $e) {
            return $this->resultFactory->error($e->getMessage(), $e->retryable, $e->hint);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'librarian-query',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => true,
            ]);

            return $this->resultFactory->error('Failed to query library.', true, 'Verify the library is indexed and try again.');
        }
    }
}
