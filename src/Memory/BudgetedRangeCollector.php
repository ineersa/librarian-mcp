<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\OmSourceBlockBuilder;
use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Collects one Observer job's in-range source lines under the per-job byte budget.
 *
 * Only events OmSourceBlockBuilder renders are kept and counted, so skipped records (Claude
 * progress, metadata and sidechain lines; Hatfield control events) never spend the budget.
 *
 * The builder pairs a call with its result only inside one read, so past the budget a job
 * keeps reading until no tool call is waiting for its result. A call can legitimately stay
 * open for a long stretch (a long-running subagent while the conversation continues), so at
 * twice the budget the job stops anyway and records where each open call's line is. The
 * continuation re-reads exactly those lines (see SourceCheckpoint::$openToolCalls) and the
 * builder pairs them with their results there.
 */
final class BudgetedRangeCollector
{
    /** Same event types and fields OmSourceBlockBuilder pairs on. */
    private const string EVENT_TOOL_CALLS = 'llm_step_completed';
    private const string EVENT_TOOL_RESULT = 'tool_execution_end';

    /** @var list<SessionEventDTO> */
    private array $events = [];
    /** @var array<string, OpenToolCallRef> */
    private array $openCalls = [];
    /** @var array{seq: int, end: int}|null */
    private ?array $last = null;
    private int $spent = 0;
    private bool $truncated = false;

    /**
     * @param list<OpenToolCallRef> $carried calls a previous forced cut left open
     */
    public function __construct(
        private readonly int $endSeq,
        array $carried = [],
        private readonly int $budgetBytes = JsonlLines::RANGE_BUDGET_BYTES,
    ) {
        foreach ($carried as $call) {
            $this->openCalls[$call->id] = $call;
        }
    }

    /**
     * One in-range source line (or skipped oversized line) at bytes [$start, $end).
     *
     * @param list<SessionEventDTO> $events
     *
     * @return bool false once the job should stop reading
     */
    public function add(int $seq, int $start, int $end, int $bytes, array $events): bool
    {
        $kept = false;
        foreach ($events as $event) {
            if (!\in_array($event->type, OmSourceBlockBuilder::RENDERED_EVENT_TYPES, true)) {
                continue;
            }
            $this->events[] = $event;
            $this->track($event, $start, $end);
            $kept = true;
        }
        $this->last = ['seq' => $seq, 'end' => $end];
        if ($kept) {
            $this->spent += $bytes;
        }
        if ($seq >= $this->endSeq || $this->spent < $this->budgetBytes) {
            return true;
        }
        if ([] === $this->openCalls) {
            $this->truncated = true;

            return false;
        }
        if ($this->spent < 2 * $this->budgetBytes) {
            return true;
        }
        $this->truncated = true;

        return false;
    }

    /**
     * Re-read the lines of calls a forced cut left open, keeping only those calls.
     *
     * @param resource                                                $handle
     * @param list<OpenToolCallRef>                                   $refs
     * @param callable(string $line, int $seq): list<SessionEventDTO> $eventsForLine
     *
     * @return array{events: list<SessionEventDTO>, refs: list<OpenToolCallRef>} only the calls found again
     */
    public static function readCarried($handle, int $cut, array $refs, callable $eventsForLine): array
    {
        /** @var array<int, list<OpenToolCallRef>> $byLine */
        $byLine = [];
        foreach ($refs as $ref) {
            $byLine[$ref->offset][] = $ref;
        }
        $events = [];
        $found = [];
        foreach ($byLine as $offset => $lineRefs) {
            $line = JsonlLines::lineAt($handle, $offset, $lineRefs[0]->length, $cut);
            if (null === $line) {
                continue;
            }
            $ids = array_fill_keys(array_map(static fn (OpenToolCallRef $ref): string => $ref->id, $lineRefs), true);
            foreach ($eventsForLine($line, $lineRefs[0]->seq) as $event) {
                $assistant = $event->payload['assistant_message'] ?? null;
                if (self::EVENT_TOOL_CALLS !== $event->type || !\is_array($assistant) || !\is_array($assistant['tool_calls'] ?? null)) {
                    continue;
                }
                $calls = array_values(array_filter(
                    $assistant['tool_calls'],
                    static fn (mixed $call): bool => \is_array($call) && isset($ids[(string) ($call['id'] ?? $call['tool_call_id'] ?? '')]),
                ));
                if ([] === $calls) {
                    continue;
                }
                $assistant['tool_calls'] = $calls;
                $events[] = new SessionEventDTO($event->runId, $event->seq, $event->turnNo, $event->type, ['assistant_message' => $assistant] + $event->payload, $event->createdAt);
                foreach ($lineRefs as $ref) {
                    $found[] = $ref;
                }
            }
        }

        return ['events' => $events, 'refs' => $found];
    }

    /**
     * @param resource              $handle  the open source, for the end checkpoint's boundary hash
     * @param list<SessionEventDTO> $carried calls re-read from the previous forced cut
     */
    public function result($handle, array $carried = []): SessionSourceRange
    {
        $last = $this->last;
        // Open calls only travel with a forced cut; at the requested end they flush unmatched as before.
        $openCalls = $this->truncated ? $this->carriedCalls() : [];

        return new SessionSourceRange(
            $this->events,
            $last['seq'] ?? null,
            null === $last ? null : JsonlLines::checkpoint($handle, $last['seq'], $last['end'], $openCalls),
            $this->truncated,
            $carried,
        );
    }

    /**
     * Earliest open calls first, bounded so re-reading them stays within one job's budget.
     *
     * @return list<OpenToolCallRef>
     */
    private function carriedCalls(): array
    {
        $calls = array_values($this->openCalls);
        usort($calls, static fn (OpenToolCallRef $a, OpenToolCallRef $b): int => $a->offset <=> $b->offset);
        $carried = [];
        $lines = [];
        $bytes = 0;
        foreach ($calls as $call) {
            if (!isset($lines[$call->offset])) {
                if (\count($lines) >= JsonlLines::MAX_CARRIED_LINES || $bytes + $call->length > $this->budgetBytes) {
                    break;
                }
                $lines[$call->offset] = true;
                $bytes += $call->length;
            }
            $carried[] = $call;
        }

        return $carried;
    }

    private function track(SessionEventDTO $event, int $start, int $end): void
    {
        if (self::EVENT_TOOL_CALLS === $event->type) {
            $assistant = $event->payload['assistant_message'] ?? null;
            $toolCalls = \is_array($assistant) ? ($assistant['tool_calls'] ?? null) : null;
            foreach (\is_array($toolCalls) ? $toolCalls : [] as $toolCall) {
                $id = \is_array($toolCall) ? (string) ($toolCall['id'] ?? $toolCall['tool_call_id'] ?? '') : '';
                if ('' !== $id) {
                    $this->openCalls[$id] = new OpenToolCallRef($id, $event->seq, $start, $end - $start);
                }
            }
        } elseif (self::EVENT_TOOL_RESULT === $event->type) {
            $result = $event->payload['tool_result'] ?? null;
            $id = \is_array($result) ? ($result['tool_call_id'] ?? null) : null;
            if (\is_string($id) || \is_int($id)) {
                unset($this->openCalls[(string) $id]);
            }
        }
    }
}
