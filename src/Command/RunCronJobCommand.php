<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CronJob;
use App\Message\RunCronJobMessage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a cron job on the `cron` transport, like the admin "Run now" action. The worker runs it
 * because only the worker has the backup credentials and writable work directory mounted.
 */
#[AsCommand(
    name: 'app:cron:run',
    description: 'Queue a cron job for the worker; it is skipped if it already succeeded today unless --force.',
)]
final readonly class RunCronJobCommand
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Cron job name, e.g. "backup"')]
        CronJob $job,
        #[Option(description: 'Run even if the job already succeeded today')]
        bool $force = false,
    ): int {
        $this->messageBus->dispatch(new RunCronJobMessage($job, $force));
        $io->success(\sprintf('%s queued%s. See Cron runs in the admin for its result.', $job->label(), $force ? ' (forced)' : ''));

        return Command::SUCCESS;
    }
}
