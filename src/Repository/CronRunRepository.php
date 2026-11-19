<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronJob;
use App\Entity\CronRun;
use App\Entity\CronRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronRun>
 *
 * @method CronRun|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method CronRun|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<CronRun> findAll()
 * @method list<CronRun> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class CronRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronRun::class);
    }

    public function hasSucceededOn(CronJob $job, \DateTimeImmutable $day): bool
    {
        return null !== $this->findOneBy(['job' => $job, 'runDay' => $day, 'status' => CronRunStatus::Succeeded]);
    }

    /**
     * Runs left `running` by a killed worker. Only meaningful while holding the job lock.
     *
     * @return list<CronRun>
     */
    public function findRunning(CronJob $job): array
    {
        return $this->findBy(['job' => $job, 'status' => CronRunStatus::Running]);
    }
}
