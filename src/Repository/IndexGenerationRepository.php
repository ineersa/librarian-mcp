<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndexGeneration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndexGeneration>
 *
 * @method IndexGeneration|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method IndexGeneration|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<IndexGeneration> findAll()
 * @method list<IndexGeneration> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class IndexGenerationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexGeneration::class);
    }

    public function findActiveForProject(int $projectId): ?IndexGeneration
    {
        return $this->findOneBy(
            ['project' => $projectId, 'active' => true],
            ['id' => 'DESC'],
        );
    }

    public function findLatestForProject(int $projectId): ?IndexGeneration
    {
        return $this->findOneBy(
            ['project' => $projectId],
            ['id' => 'DESC'],
        );
    }

    /**
     * @return list<IndexGeneration>
     */
    public function findObsoleteForProject(int $projectId, int $keepGenerationId): array
    {
        /** @var list<IndexGeneration> $generations */
        $generations = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.project) = :projectId')
            ->andWhere('g.id != :keepId')
            ->setParameter('projectId', $projectId)
            ->setParameter('keepId', $keepGenerationId)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $generations;
    }
}
