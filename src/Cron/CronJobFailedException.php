<?php

declare(strict_types=1);

namespace App\Cron;

final class CronJobFailedException extends \RuntimeException
{
    /**
     * @param list<string> $results work completed before the failure
     */
    public function __construct(string $message, public readonly array $results = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
