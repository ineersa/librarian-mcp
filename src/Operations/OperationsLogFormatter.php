<?php

declare(strict_types=1);

namespace App\Operations;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class OperationsLogFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;
        $exception = $exception instanceof HandlerFailedException ? $exception->getPrevious() : $exception;
        $exceptionClass = $exception instanceof \Throwable ? $exception::class : null;
        $retry = [];
        if (RetryLogSubscriber::CHANNEL === $record->channel) {
            $exceptionClass = $record->context['exceptionClass'] ?? null;
            $retry = [
                'transport' => $record->context['transport'] ?? null,
                'job' => $record->context['job'] ?? null,
                'attempt' => $record->context['attempt'] ?? null,
                'nextAt' => $record->context['nextAt'] ?? null,
            ];
        }

        // Never persist arbitrary log messages, exception messages, or the context array.
        return json_encode([
            'at' => $record->datetime->getTimestamp(),
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'summary' => match (true) {
                MaxIterationsExceededException::class === $exceptionClass => 'Tool-calling round limit exceeded.',
                \is_string($exceptionClass) && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,199}$/D', $exceptionClass) => $exceptionClass,
                [] !== $retry => 'The previous attempt did not complete.',
                default => 'Application diagnostic; see the server log for details.',
            },
        ] + $retry, \JSON_THROW_ON_ERROR)."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
