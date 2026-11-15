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
     * @param list<int> $projectIds
     *
     * @return array<int, IndexGeneration>
     */
    public function findLatestByProjectIds(array $projectIds): array
    {
        return $this->mapLatestPerProject($projectIds, activeOnly: false);
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, IndexGeneration>
     */
    public function findActiveByProjectIds(array $projectIds): array
    {
        return $this->mapLatestPerProject($projectIds, activeOnly: true);
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

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, IndexGeneration>
     */
    private function mapLatestPerProject(array $projectIds, bool $activeOnly): array
    {
        $ids = array_values(array_unique(array_filter(
            $projectIds,
            static fn (mixed $id): bool => \is_int($id) && $id > 0,
        )));
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('g')
            ->andWhere('IDENTITY(g.project) IN (:projectIds)')
            ->setParameter('projectIds', $ids)
            ->orderBy('g.id', 'DESC');
        if ($activeOnly) {
            $qb->andWhere('g.active = true');
        }

        /** @var list<IndexGeneration> $generations */
        $generations = $qb->getQuery()->getResult();

        $byProject = [];
        foreach ($generations as $generation) {
            $projectId = (int) $generation->project->id;
            if (!isset($byProject[$projectId])) {
                $byProject[$projectId] = $generation;
            }
        }

        return $byProject;
    }
}
