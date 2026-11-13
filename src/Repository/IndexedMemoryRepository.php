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
        // Aggregate in SQLite: never hydrate the entire populated manifest in PHP.
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(json_array_length(chunk_ids)), 0) FROM indexed_memories WHERE generation_id = ?',
            [$generationId],
        );
    }

    /**
     * @param list<array{kind: string, source_id: string, signature: string, chunk_id: string}> $candidates
     *
     * @return list<string>
     */
    public function findCommittedChunkIds(int $generationId, array $candidates): array
    {
        if ([] === $candidates) {
            return [];
        }

        // One bounded JSON input avoids one query per candidate and SQLite's
        // bind-parameter limit while preserving all provenance predicates.
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT json_extract(c.value, \'$.chunk_id\')
             FROM json_each(:candidates) c
             JOIN indexed_memories m
               ON m.generation_id = :generation
              AND m.kind = json_extract(c.value, \'$.kind\')
              AND m.source_id = json_extract(c.value, \'$.source_id\')
              AND m.content_signature = json_extract(c.value, \'$.signature\')
             WHERE EXISTS (
                 SELECT 1 FROM json_each(m.chunk_ids)
                 WHERE value = json_extract(c.value, \'$.chunk_id\')
             )',
            ['generation' => $generationId, 'candidates' => json_encode($candidates, \JSON_THROW_ON_ERROR)],
        );
    }
}
