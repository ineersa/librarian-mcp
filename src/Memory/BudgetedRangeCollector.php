<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Collects one Observer job's in-range source lines under the per-job byte budget.
 *
 * The budget never splits a tool group: OmSourceBlockBuilder pairs a call with its result
 * only inside one read, so a job stops early only where no tool call is still waiting for
 * its result. If a group stays open up to the hard cap, the read rolls back to the last
 * such point; with none since the range start (one dangling call), it stops where it is so
 * the job still makes progress.
 */
final class BudgetedRangeCollector
{
    /** Same event types and fields OmSourceBlockBuilder pairs on. */
    private const string EVENT_TOOL_CALLS = 'llm_step_completed';
    private const string EVENT_TOOL_RESULT = 'tool_execution_end';

    /** @var list<SessionEventDTO> */
    private array $events = [];
    /** @var array<string, true> */
    private array $openCalls = [];
    /** @var array{seq: int, end: int}|null */
    private ?array $last = null;
    /** @var array{seq: int, end: int, count: int}|null */
    private ?array $clean = null;
    private int $spent = 0;
    private bool $truncated = false;

    public function __construct(
        private readonly int $endSeq,
        private readonly int $budgetBytes = JsonlLines::RANGE_BUDGET_BYTES,
    ) {
    }

    /**
     * One in-range source line (or skipped oversized line) ending at byte $end.
     *
     * @param list<SessionEventDTO> $events
     *
     * @return bool false once the job should stop reading
     */
    public function add(int $seq, int $end, int $bytes, array $events): bool
    {
        foreach ($events as $event) {
            $this->events[] = $event;
            $this->track($event);
        }
        $this->last = ['seq' => $seq, 'end' => $end];
        if ([] === $this->openCalls) {
            $this->clean = ['seq' => $seq, 'end' => $end, 'count' => \count($this->events)];
        }
        $this->spent += $bytes;
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
        if (null !== $this->clean) {
            $this->events = \array_slice($this->events, 0, $this->clean['count']);
            $this->last = ['seq' => $this->clean['seq'], 'end' => $this->clean['end']];
        }

        return false;
    }

    /**
     * @param resource $handle the open source, for the end checkpoint's boundary hash
     */
    public function result($handle): SessionSourceRange
    {
        $last = $this->last;

        return new SessionSourceRange(
            $this->events,
            $last['seq'] ?? null,
            null === $last ? null : JsonlLines::checkpoint($handle, $last['seq'], $last['end']),
            $this->truncated,
        );
    }

    private function track(SessionEventDTO $event): void
    {
        if (self::EVENT_TOOL_CALLS === $event->type) {
            $assistant = $event->payload['assistant_message'] ?? null;
            $toolCalls = \is_array($assistant) ? ($assistant['tool_calls'] ?? null) : null;
            foreach (\is_array($toolCalls) ? $toolCalls : [] as $toolCall) {
                $id = \is_array($toolCall) ? (string) ($toolCall['id'] ?? $toolCall['tool_call_id'] ?? '') : '';
                if ('' !== $id) {
                    $this->openCalls[$id] = true;
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
