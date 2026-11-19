<?php

declare(strict_types=1);

namespace App\Cron;

use App\Entity\CronJob;
use App\Entity\CronRun;
use App\Entity\CronRunStatus;
use App\Repository\CronRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs a cron job at most once successfully per day (display timezone) unless forced.
 *
 * Run rows are written in short flushes before and after the work, never inside one transaction.
 */
final class CronJobRunner
{
    private const string LOCK_PREFIX = 'cron-job:';
    /** Refreshed through the task heartbeat between long steps. */
    private const float LOCK_TTL_SECONDS = 3600.0;
    private const string INTERRUPTED_ERROR = 'Interrupted before completion (worker stopped).';

    /** @var array<string, CronJobTask> */
    private array $tasks = [];

    /**
     * @param iterable<CronJobTask> $tasks
     */
    public function __construct(
        #[AutowireIterator(CronJobTask::class)]
        iterable $tasks,
        private readonly CronRunRepository $cronRunRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.display_timezone')]
        private readonly string $displayTimezone,
    ) {
        foreach ($tasks as $task) {
            $this->tasks[$task->job()->value] = $task;
        }
    }

    public function run(CronJob $job, bool $force): CronRunOutcome
    {
        $task = $this->tasks[$job->value] ?? throw new \LogicException(\sprintf('No task is registered for cron job "%s".', $job->value));
        $day = $this->today();
        if (!$force && $this->cronRunRepository->hasSucceededOn($job, $day)) {
            return CronRunOutcome::Skipped;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_PREFIX.$job->value, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return CronRunOutcome::Busy;
        }

        try {
            if (!$force && $this->cronRunRepository->hasSucceededOn($job, $day)) {
                return CronRunOutcome::Skipped;
            }
            // Holding the lock, any row still `running` belongs to a worker that died mid-run.
            foreach ($this->cronRunRepository->findRunning($job) as $stale) {
                $stale->fail(self::INTERRUPTED_ERROR, $stale->results, $this->clock->now());
            }

            $run = new CronRun($job, $day, $force, $this->clock->now());
            $this->entityManager->persist($run);
            $this->entityManager->flush();

            try {
                $run->succeed($task->run(static fn () => $lock->refresh()), $this->clock->now());
            } catch (CronJobFailedException $exception) {
                $run->fail($exception->getMessage(), $exception->results, $this->clock->now());
            } catch (\Throwable $exception) {
                $this->logger->error('Cron job {job} failed.', ['job' => $job->value, 'exception' => $exception]);
                $run->fail($exception->getMessage(), [], $this->clock->now());
            }
            $this->entityManager->flush();

            return CronRunStatus::Succeeded === $run->status ? CronRunOutcome::Succeeded : CronRunOutcome::Failed;
        } finally {
            $lock->release();
        }
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->displayTimezone))->setTime(0, 0);
    }
}
