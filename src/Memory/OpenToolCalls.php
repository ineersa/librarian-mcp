<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Tool calls still waiting for their result, tracked on the same event types and fields
 * OmSourceBlockBuilder pairs on. The builder pairs a call with its result only inside one
 * read, so range boundaries must not separate them unless the open calls are carried.
 */
final class OpenToolCalls
{
    public const string EVENT_TOOL_CALLS = 'llm_step_completed';
    public const string EVENT_TOOL_RESULT = 'tool_execution_end';

    /** @var array<string, OpenToolCallRef> */
    private array $calls = [];

    /**
     * @param list<OpenToolCallRef> $carried
     */
    public function __construct(array $carried = [])
    {
        foreach ($carried as $call) {
            $this->calls[$call->id] = $call;
        }
    }

    /** One rendered event read from the source line at bytes [$start, $end). */
    public function track(SessionEventDTO $event, int $start, int $end): void
    {
        if (self::EVENT_TOOL_CALLS === $event->type) {
            $assistant = $event->payload['assistant_message'] ?? null;
            $toolCalls = \is_array($assistant) ? ($assistant['tool_calls'] ?? null) : null;
            foreach (\is_array($toolCalls) ? $toolCalls : [] as $toolCall) {
                $id = \is_array($toolCall) ? (string) ($toolCall['id'] ?? $toolCall['tool_call_id'] ?? '') : '';
                if ('' !== $id) {
                    $this->calls[$id] = new OpenToolCallRef($id, $event->seq, $start, $end - $start);
                }
            }
        } elseif (self::EVENT_TOOL_RESULT === $event->type) {
            $result = $event->payload['tool_result'] ?? null;
            $id = \is_array($result) ? ($result['tool_call_id'] ?? null) : null;
            if (\is_string($id) || \is_int($id)) {
                unset($this->calls[(string) $id]);
            }
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->calls;
    }

    /**
     * Earliest open calls first.
     *
     * @return list<OpenToolCallRef>
     */
    public function sorted(): array
    {
        $calls = array_values($this->calls);
        usort($calls, static fn (OpenToolCallRef $a, OpenToolCallRef $b): int => $a->offset <=> $b->offset);

        return $calls;
    }
}
