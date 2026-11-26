<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LibrarianSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LibrarianSettings>
 *
 * @method LibrarianSettings|null  find(mixed $id, int|null $lockMode = null, int|null $lockVersion = null)
 * @method LibrarianSettings|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<LibrarianSettings> findAll()
 * @method list<LibrarianSettings> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, int|null $limit = null, int|null $offset = null)
 */
class LibrarianSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibrarianSettings::class);
    }

    public function current(): ?LibrarianSettings
    {
        return $this->findOneBy(['slot' => LibrarianSettings::ACTIVE_SLOT]);
    }
}
