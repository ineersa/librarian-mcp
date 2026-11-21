<?php

declare(strict_types=1);

namespace App\Operations;

use Monolog\Formatter\FormatterInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class OperationsLogFormatter implements FormatterInterface
{
    private const string APP_CHANNEL = 'app';
    private const string REQUEST_CHANNEL = 'request';
    private const string MESSENGER_CHANNEL = 'messenger';

    private const array APP_SUMMARIES = [
        'Project status publish deferred.' => 'Project status publish deferred.',
        'Messenger dashboard update could not be published.' => 'Messenger dashboard update could not be published.',
        'Messenger dashboard stats are unavailable.' => 'Messenger dashboard stats are unavailable.',
        'Project source discovery failed unexpectedly.' => 'Project source discovery failed unexpectedly.',
        'Obsolete index cleanup deferred until the next indexing tick.' => 'Obsolete index cleanup deferred until the next indexing tick.',
        'Unable to persist sanitized index failure state.' => 'Unable to persist the index failure status.',
        'Cron job {job} failed.' => 'A scheduled job failed.',
        'Backup of project {project} failed: root {root} is unavailable.' => 'A project backup failed because its source directory is unavailable.',
        'Backup of {source} failed.' => 'A backup failed.',
        'om.reflect.threshold_failed' => 'Could not evaluate the memory reflection threshold.',
        'om.agent.run.failed' => 'A memory agent run failed.',
        'Memory operation failed.' => 'A memory operation failed.',
    ];

    public function format(LogRecord $record): string
    {
        // Omit routine request warnings (including 4xx) from the admin feed, not from normal logging.
        if (self::REQUEST_CHANNEL === $record->channel && $record->level->isLowerThan(Level::Error)) {
            return '';
        }

        // Symfony logs this before sending; the retry subscriber records it once after a successful send.
        if (self::MESSENGER_CHANNEL === $record->channel && Level::Warning === $record->level && \is_int($record->context['retryCount'] ?? null) && \is_int($delay = $record->context['delay'] ?? null) && 0 < $delay) {
            return '';
        }

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

        $appSummary = self::APP_CHANNEL === $record->channel ? self::APP_SUMMARIES[$record->message] ?? null : null;

        // Persist only fixed summaries or a validated exception class, never interpolated messages.
        return json_encode([
            'at' => $record->datetime->getTimestamp(),
            'level' => $record->level->getName(),
            'channel' => $record->channel,
            'summary' => $appSummary ?? match (true) {
                MaxIterationsExceededException::class === $exceptionClass => 'Tool-calling round limit exceeded.',
                TimeoutException::class === $exceptionClass => 'An outbound HTTP request timed out.',
                TransportException::class === $exceptionClass => 'An outbound HTTP request could not be completed.',
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
