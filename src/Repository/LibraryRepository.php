<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Library;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Library>
 *
 * @method Library|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method Library|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<Library> findAll()
 * @method list<Library> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class LibraryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Library::class);
    }

    public function findOneByPath(string $path): ?Library
    {
        return $this->findOneBy(['path' => $path]);
    }

    public function findOneBySlug(string $slug): ?Library
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<Library> */
    public function findReadyByMetadataLike(string $query, int $limit): array
    {
        $like = '%'.mb_strtolower($query).'%';

        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->andWhere('LOWER(l.name) LIKE :like OR LOWER(l.slug) LIKE :like OR LOWER(l.description) LIKE :like OR LOWER(l.gitUrl) LIKE :like')
            ->setParameter('status', \App\Entity\LibraryStatus::Ready)
            ->setParameter('like', $like)
            ->setMaxResults($limit)
            ->orderBy('l.lastIndexedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<Library>
     */
    public function findReadyBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->andWhere('l.slug IN (:slugs)')
            ->setParameter('status', \App\Entity\LibraryStatus::Ready)
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getResult();
    }
}
