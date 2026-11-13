<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 *
 * @method Project|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method Project|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<Project> findAll()
 * @method list<Project> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }
}
