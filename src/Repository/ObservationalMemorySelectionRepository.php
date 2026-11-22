<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ObservationalMemorySelection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObservationalMemorySelection>
 *
 * @method ObservationalMemorySelection|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method ObservationalMemorySelection|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<ObservationalMemorySelection> findAll()
 * @method list<ObservationalMemorySelection> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class ObservationalMemorySelectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObservationalMemorySelection::class);
    }

    public function current(): ?ObservationalMemorySelection
    {
        return $this->findOneBy(['slot' => ObservationalMemorySelection::ACTIVE_SLOT]);
    }
}
