<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndexedMemory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndexedMemory>
 *
 * @method IndexedMemory|null find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 */
class IndexedMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexedMemory::class);
    }

    public function findCommitted(int $generationId, string $kind, string $sourceId): ?IndexedMemory
    {
        return $this->findOneBy(['generation' => $generationId, 'kind' => $kind, 'sourceId' => $sourceId]);
    }

    /**
     * @param list<string> $sourceIds
     *
     * @return list<IndexedMemory>
     */
    public function findParents(int $generationId, array $sourceIds): array
    {
        if ([] === $sourceIds) {
            return [];
        }

        return $this->findBy(['generation' => $generationId, 'sourceId' => $sourceIds]);
    }

    /** @param list<int> $ids */
    public function markSeen(array $ids, int $scanRound): void
    {
        if ([] !== $ids) {
            $this->createQueryBuilder('m')->update()
                ->set('m.lastSeenRound', ':round')->where('m.id IN (:ids)')
                ->setParameter('round', $scanRound)->setParameter('ids', $ids)
                ->getQuery()->execute();
        }
    }

    /** @return list<IndexedMemory> */
    public function findUnseen(int $generationId, int $scanRound, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.generation) = :generationId')
            ->andWhere('m.lastSeenRound < :scanRound')
            ->setParameter('generationId', $generationId)
            ->setParameter('scanRound', $scanRound)
            ->orderBy('m.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
