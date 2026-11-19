<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt of a cron job. Skipped ticks (job already succeeded that day) are not recorded.
 */
#[ORM\Entity(repositoryClass: CronRunRepository::class)]
#[ORM\Table(name: 'cron_runs')]
#[ORM\Index(name: 'idx_cron_runs_job_day', columns: ['job', 'run_day'])]
class CronRun
{
    /** Error summaries stay short; full traces belong in logs. */
    public const int MAX_ERROR_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: CronJob::class)]
    public private(set) CronJob $job;

    /** Calendar day (display timezone) the run counts for. */
    #[ORM\Column(name: 'run_day', type: Types::DATE_IMMUTABLE)]
    public private(set) \DateTimeImmutable $runDay;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CronRunStatus::class)]
    public private(set) CronRunStatus $status = CronRunStatus::Running;

    #[ORM\Column]
    public private(set) bool $forced;

    #[ORM\Column(name: 'started_at')]
    public private(set) \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    public private(set) ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $error = null;

    /**
     * Job output shown in the admin, e.g. uploaded object URIs.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $results = [];

    public function __construct(CronJob $job, \DateTimeImmutable $runDay, bool $forced, \DateTimeImmutable $now)
    {
        $this->job = $job;
        $this->runDay = $runDay;
        $this->forced = $forced;
        $this->startedAt = $now;
    }

    /**
     * @param list<string> $results
     */
    public function succeed(array $results, \DateTimeImmutable $now): void
    {
        $this->status = CronRunStatus::Succeeded;
        $this->results = $results;
        $this->finishedAt = $now;
    }

    /**
     * @param list<string> $results whatever completed before the failure
     */
    public function fail(string $error, array $results, \DateTimeImmutable $now): void
    {
        $this->status = CronRunStatus::Failed;
        $this->error = mb_substr($error, 0, self::MAX_ERROR_LENGTH);
        $this->results = $results;
        $this->finishedAt = $now;
    }
}
