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
}
