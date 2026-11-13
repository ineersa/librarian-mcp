<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndexedMemory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndexedMemory>
 *
 * @method IndexedMemory|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method IndexedMemory|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<IndexedMemory> findAll()
 * @method list<IndexedMemory> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class IndexedMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndexedMemory::class);
    }

    public function findCommitted(int $generationId, string $kind, string $sourceId): ?IndexedMemory
    {
        return $this->findOneBy([
            'generation' => $generationId,
            'kind' => $kind,
            'sourceId' => $sourceId,
        ]);
    }

    /**
     * @return list<IndexedMemory>
     */
    public function findUnseen(int $generationId, int $scanRound, int $limit): array
    {
        /** @var list<IndexedMemory> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.generation) = :generationId')
            ->andWhere('m.lastSeenRound < :scanRound')
            ->setParameter('generationId', $generationId)
            ->setParameter('scanRound', $scanRound)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countParents(int $generationId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('IDENTITY(m.generation) = :generationId')
            ->setParameter('generationId', $generationId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumChunkCount(int $generationId): int
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.chunkIds')
            ->andWhere('IDENTITY(m.generation) = :generationId')
            ->setParameter('generationId', $generationId)
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        foreach ($rows as $row) {
            $chunkIds = $row['chunkIds'] ?? [];
            if (\is_array($chunkIds)) {
                $total += \count($chunkIds);
            }
        }

        return $total;
    }
}
