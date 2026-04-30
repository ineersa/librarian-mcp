<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class LibrarianReadTool
{
    public function __construct(
        private ReadyLibraryResolver $readyLibraryResolver,
        private LibraryManager $libraryManager,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(name: 'librarian-read', description: 'Read safe text window from one ready library')]
    public function read(string $library, string $file, int $offset = 1, int $limit = 200): CallToolResult
    {
        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $offset = max(1, $offset);
            $limit = max(1, min(2000, $limit));

            $absoluteRoot = $this->libraryManager->getAbsolutePath($target);
            $relativePath = ltrim(str_replace('\\', '/', $file), '/');

            if (!isset($target->getReadableFiles()[$relativePath])) {
                throw new ToolCallException('Requested path is not readable.', false, 'Use librarian-query/librarian-grep first, then request an indexed text file path.');
            }

            $realPath = realpath($absoluteRoot.'/'.$relativePath);
            $realRoot = realpath($absoluteRoot);

            if (false === $realPath || false === $realRoot || !str_starts_with(str_replace('\\', '/', $realPath), str_replace('\\', '/', $realRoot).'/') || !is_file($realPath)) {
                throw new ToolCallException('Requested path is not readable.', false, 'Use a relative path inside the target library repository.');
            }

            $lines = file($realPath, \FILE_IGNORE_NEW_LINES);
            if (false === $lines) {
                throw new ToolCallException('Requested path is not readable.', false, 'The file could not be read.');
            }

            $startIndex = $offset - 1;
            $slice = \array_slice($lines, $startIndex, $limit);

            return $this->resultFactory->success([
                'library' => $target->getSlug(),
                'file' => $relativePath,
                'offset' => $offset,
                'limit' => $limit,
                'totalLines' => \count($lines),
                'lines' => array_map(
                    static fn (string $line, int $index): array => [
                        'line' => $startIndex + $index + 1,
                        'text' => $line,
                    ],
                    $slice,
                    array_keys($slice),
                ),
            ]);
        } catch (ToolCallException $e) {
            return $this->resultFactory->error($e->getMessage(), $e->retryable, $e->hint);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failure', [
                'tool' => 'librarian-read',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => false,
            ]);

            return $this->resultFactory->error('Failed to read file window.', false, 'Verify the path and retry with an indexed text file.');
        }
    }
}
