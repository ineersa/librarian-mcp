<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Memory\MemoryErrorCode;
use App\Memory\MemoryRecall;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Psr\Log\LoggerInterface;

/** Native SDK tools; connection context must never become model-facing arguments. */
final readonly class MemoryTools
{
    public const string ENSURE_PROJECT = 'ensure_project';
    public const string SEARCH = 'memory_search';
    public const string RECALL = 'recall';
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 50;

    // Explicit native SDK schemas keep connection context out of tool arguments.
    public const array ENSURE_PROJECT_SCHEMA = [
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ];

    public const array SEARCH_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['query'],
        'properties' => [
            'query' => [
                'type' => 'string', 'minLength' => 1,
                'description' => 'Keywords, identifiers, concepts, paraphrases, or natural-language descriptions of prior work. Phrases are search hints, not exact-match constraints.',
            ],
            'after' => [
                'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                'description' => 'Optional inclusive lower bound on memory date (YYYY-MM-DD or YYYY-MM-DD HH:MM). Omit to search all retained history.',
            ],
            'before' => [
                'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
                'description' => 'Optional inclusive upper bound on memory date (YYYY-MM-DD or YYYY-MM-DD HH:MM). Omit to search all retained history.',
            ],
            'limit' => [
                'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT,
                'description' => 'Maximum relevance-ranked results (default 20, max 50), not newest first. Retrieval uses bounded candidates; truncated: true means a candidate or result limit was reached, not that more relevant memories exist. No total or pagination.',
            ],
        ],
    ];

    public const array RECALL_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['id'],
        'properties' => [
            'id' => [
                'type' => 'string', 'pattern' => '^[a-f0-9]{12,64}$',
                'description' => 'Full lowercase hex observation or reflection id, or a unique 12–64 character prefix from compacted memory, /om-view, memory_search, or a previous recall result. Not a topic search.',
            ],
        ],
    ];
    private const int MAX_RESULT_BYTES = 1_048_576;

    public function __construct(
        private MemoryContextResolver $context,
        private EnsureProject $registration,
        private MemorySearch $search,
        private MemoryRecall $recall,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: self::ENSURE_PROJECT,
        description: 'Ensure the project selected by X-Librarian-Project is registered. Call once during connection setup with empty arguments. Existing registrations are returned unchanged; new registrations use the directory basename and queue normal asynchronous discovery. Success means registration exists, not that sources or indexing are ready. Never creates or modifies source directories, databases, settings, or journals.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[Schema(definition: self::ENSURE_PROJECT_SCHEMA)]
    public function ensureProject(): CallToolResult
    {
        return $this->run(self::ENSURE_PROJECT, fn (): array => $this->registration->ensure($this->context->canonicalRoot()));
    }

    #[McpTool(
        name: self::SEARCH,
        description: 'Find prior work across sessions in the connected registered project using hybrid keyword and semantic search. Searches retained memory content, not raw transcript events. Ranked hits may be unrelated; inspect them before using them as evidence. partial: true means newer or changed memories may be missing. Use recall for source context behind useful hits.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[Schema(definition: self::SEARCH_SCHEMA)]
    public function memorySearch(string $query, ?string $after = null, ?string $before = null, int $limit = self::DEFAULT_LIMIT): CallToolResult
    {
        return $this->run(self::SEARCH, fn (): array => $this->search->search($this->context->project(), $query, $after, $before, $limit));
    }

    #[McpTool(
        name: self::RECALL,
        description: 'Recover exact evidence and source context for one memory by ID across the connected registered project. The source session is derived from the matched memory. Ambiguous prefixes return full IDs and memory content; choose the intended memory and retry with its full ID. Not semantic search or transcript browsing. Verify current repository or PR state before acting on historical evidence.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[Schema(definition: self::RECALL_SCHEMA)]
    public function recall(string $id): CallToolResult
    {
        return $this->run(self::RECALL, function () use ($id): array {
            $project = $this->context->project();

            return $this->recall->recall($project, $id)
                + ['project' => MemoryContextResolver::provenance($project)];
        }, enforcePayloadLimit: false);
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function run(string $operation, callable $action, bool $enforcePayloadLimit = true): CallToolResult
    {
        try {
            $payload = $action();
        } catch (MemoryToolException $error) {
            $payload = ['ok' => false, 'error' => $error->errorCode->value, 'message' => $error->getMessage()];
        } catch (\Throwable $error) {
            // Never pass source/provider exception objects or arguments to logs/the SDK.
            $this->logger->error('Memory operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
            $payload = ['ok' => false, 'error' => MemoryErrorCode::InternalError->value, 'message' => 'The memory operation could not be completed.'];
        }

        $isError = true !== ($payload['ok'] ?? false);
        if ($isError) {
            $code = \is_string($payload['error'] ?? null) ? MemoryErrorCode::tryFrom($payload['error']) : null;
            $payload['retryable'] = $code?->isRetryable() ?? false;
        }
        try {
            $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return $this->failure(MemoryErrorCode::SourceUnavailable, 'Source content cannot be represented as a JSON memory result.');
        }
        if ($enforcePayloadLimit && \strlen($json) > self::MAX_RESULT_BYTES) {
            return $this->failure(MemoryErrorCode::ResponseTooLarge, 'The memory response exceeds the 1 MiB payload limit. Narrow the search or recall a smaller memory.');
        }

        return new CallToolResult([new TextContent($json)], isError: $isError, structuredContent: $payload);
    }

    private function failure(MemoryErrorCode $code, string $message): CallToolResult
    {
        $payload = ['ok' => false, 'error' => $code->value, 'message' => $message, 'retryable' => $code->isRetryable()];

        return new CallToolResult([new TextContent(json_encode($payload, \JSON_THROW_ON_ERROR))], isError: true, structuredContent: $payload);
    }
}
