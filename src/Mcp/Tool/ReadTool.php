<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Service\LibraryManager;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final readonly class ReadTool
{
    public function __construct(
        private ReadyLibraryResolver $readyLibraryResolver,
        private LibraryManager $libraryManager,
        private ToonToolResultFactory $resultFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Read a line window from an indexed text file in one ready library.
     *
     * @param string $library Ready library slug, e.g. "easycorp/easyadminbundle@5.x"
     * @param string $file    Relative file path discovered via semantic-search or grep
     * @param int    $offset  1-based start line (min 1)
     * @param int    $limit   Number of lines to return (1..2000)
     */
    #[McpTool(name: 'read', description: <<<'DESC'
        Read a text file window from one ready library.
        Best used after semantic-search or grep to inspect exact matched lines.
        Returns a line-based slice with line numbers, similar to `sed -n`.
        Access is sandboxed: only files previously discovered via semantic-search or grep
        are readable, and the path must stay inside the library repository root.
        DESC)]
    public function read(string $library, string $file, int $offset = 1, int $limit = 200): CallToolResult
    {
        try {
            $target = $this->readyLibraryResolver->resolve($library);
            $offset = max(1, $offset);
            $limit = max(1, min(2000, $limit));

            $absoluteRoot = $this->libraryManager->getAbsolutePath($target);
            $relativePath = ltrim(str_replace('\\', '/', $file), '/');

            if (!isset($target->getReadableFiles()[$relativePath])) {
                throw new ToolCallException('Requested path is not readable.', false, 'Use semantic-search or grep first, then request an indexed text file path.');
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
                'tool' => 'read',
                'library' => $library,
                'error' => $e->getMessage(),
                'retryable' => false,
            ]);

            return $this->resultFactory->error('Failed to read file window.', false, 'Verify the path and retry with an indexed text file.');
        }
    }
}
