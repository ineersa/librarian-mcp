<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndexGeneration;
use App\Entity\IndexState;
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

    /**
     * Newest non-obsolete generation of any project with this embedding signature that holds vectors.
     * Includes generations still being built: chunks are stored per batch, long before the commit.
     */
    public function findPopulatedWithSignature(string $signature): ?IndexGeneration
    {
        return $this->createQueryBuilder('generation')
            ->andWhere('generation.signature = :signature')
            ->andWhere('generation.chunkCount > 0')
            ->andWhere('generation.state != :obsolete')
            ->setParameter('signature', $signature)
            ->setParameter('obsolete', IndexState::Obsolete)
            ->orderBy('generation.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
