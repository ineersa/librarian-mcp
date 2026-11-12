<?php

declare(strict_types=1);

namespace AppTasks;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/** Tracks the real subprocess of a report, including commands inside operation closures. */
final class TaskExecution
{
    public static ?self $current = null;
    public ?Process $process = null;
    public int $interrupted = 0;

    public function __construct(public readonly ?self $parent)
    {
    }

    public function throwIfInterrupted(): void
    {
        $this->parent?->throwIfInterrupted();
        if (0 !== $this->interrupted) {
            throw new \RuntimeException('Task interrupted with exit code '.$this->interrupted.'.');
        }
    }
}

/** @param (callable(string, string): void)|null $callback */
function runTaskProcess(Process $process, bool $mustSucceed = true, ?callable $callback = null): int
{
    $execution = TaskExecution::$current;
    $execution?->throwIfInterrupted();
    if (null !== $execution) {
        $execution->process = $process;
    }
    try {
        $exit = $process->run($callback);
        $execution?->throwIfInterrupted();
        if ($mustSucceed && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $exit;
    } finally {
        if (null !== $execution) {
            $execution->process = null;
        }
    }
}
