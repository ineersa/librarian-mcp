<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Cron\CronJobRunner;
use App\Message\RunCronJobMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Failures are recorded on the cron run and retried by the next schedule tick, not by Messenger.
 */
#[AsMessageHandler]
final readonly class RunCronJobMessageHandler
{
    public function __construct(
        private CronJobRunner $runner,
    ) {
    }

    public function __invoke(RunCronJobMessage $message): void
    {
        $this->runner->run($message->job, $message->force);
    }
}
