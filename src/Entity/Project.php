<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use App\Validator\ProjectRegistration;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\UniqueConstraint(name: 'uniq_project_name_normalized', columns: ['name_normalized'])]
#[ORM\UniqueConstraint(name: 'uniq_project_root_canonical', columns: ['root_canonical'])]
#[ORM\UniqueConstraint(name: 'uniq_project_repository_origin', columns: ['repository_origin'])]
#[Assert\GroupSequence(['Project', 'Unique'])]
#[ProjectRegistration(groups: ['Project'])]
#[UniqueEntity(fields: 'nameNormalized', message: 'A project with this name already exists.', errorPath: 'name', groups: ['Unique'])]
#[UniqueEntity(fields: 'rootCanonical', message: 'This project directory is already registered.', errorPath: 'rootPath', groups: ['Unique'])]
#[UniqueEntity(fields: 'repositoryOrigin', message: 'A project with this Git repository origin is already registered.', errorPath: 'rootPath', ignoreNull: true, groups: ['Unique'])]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(type: 'string', length: 255)]
    public string $name = '';

    #[ORM\Column(name: 'name_normalized', type: 'string', length: 255)]
    public private(set) string $nameNormalized = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    #[ORM\Column(name: 'root_path', type: 'string', length: 2048)]
    public string $rootPath = '';

    #[ORM\Column(name: 'root_canonical', type: 'string', length: 2048)]
    public private(set) string $rootCanonical = '';

    #[ORM\Column(name: 'repository_origin', type: 'string', length: 2048, nullable: true)]
    public private(set) ?string $repositoryOrigin = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectHealth::class)]
    public private(set) ProjectHealth $health = ProjectHealth::Pending;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $lastScanAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $lastSuccessfulScanAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $lastSuccessfulIndexAt = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    public private(set) ?string $databasePath = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    public private(set) ?string $sessionsPath = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public private(set) ?string $schemaVersion = null;

    #[ORM\Column]
    public private(set) int $observationCount = 0;

    #[ORM\Column]
    public private(set) int $reflectionCount = 0;

    #[ORM\Column]
    public private(set) int $sessionCount = 0;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $sourceRevision = null;

    #[ORM\Column]
    public private(set) int $sourceChangeCount = 0;

    /**
     * Diagnostic discovery details without source payloads.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    public private(set) array $sourceDetails = [];

    #[ORM\Column]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function assignIdentity(
        string $name,
        string $nameNormalized,
        string $rootPath,
        string $rootCanonical,
        ?string $repositoryOrigin,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->nameNormalized = $nameNormalized;
        $this->rootPath = $rootPath;
        $this->rootCanonical = $rootCanonical;
        $this->repositoryOrigin = $repositoryOrigin;
        $this->touch($now);
    }

    public function markPendingScan(\DateTimeImmutable $now): void
    {
        $this->health = ProjectHealth::Pending;
        $this->lastError = null;
        $this->touch($now);
    }

    public function markScanning(\DateTimeImmutable $now): void
    {
        $this->health = ProjectHealth::Scanning;
        $this->lastScanAt = $now;
        $this->lastError = null;
        $this->touch($now);
    }

    /**
     * @param array<string, mixed> $sourceDetails
     */
    public function markReady(
        \DateTimeImmutable $now,
        ?string $databasePath,
        ?string $sessionsPath,
        ?string $schemaVersion,
        int $observationCount,
        int $reflectionCount,
        int $sessionCount,
        ?int $sourceRevision,
        int $sourceChangeCount,
        array $sourceDetails,
    ): void {
        $this->health = ProjectHealth::Ready;
        $this->lastError = null;
        $this->lastScanAt = $now;
        $this->lastSuccessfulScanAt = $now;
        $this->databasePath = $databasePath;
        $this->sessionsPath = $sessionsPath;
        $this->schemaVersion = $schemaVersion;
        $this->observationCount = $observationCount;
        $this->reflectionCount = $reflectionCount;
        $this->sessionCount = $sessionCount;
        $this->sourceRevision = $sourceRevision;
        $this->sourceChangeCount = $sourceChangeCount;
        $this->sourceDetails = $sourceDetails;
        $this->touch($now);
    }

    /**
     * @param array<string, mixed> $sourceDetails
     */
    public function markError(
        \DateTimeImmutable $now,
        string $error,
        array $sourceDetails = [],
        ?string $databasePath = null,
        ?string $sessionsPath = null,
        ?string $schemaVersion = null,
        int $observationCount = 0,
        int $reflectionCount = 0,
        int $sessionCount = 0,
        ?int $sourceRevision = null,
        int $sourceChangeCount = 0,
    ): void {
        $this->health = ProjectHealth::Error;
        $this->lastError = mb_substr($error, 0, 2000);
        $this->lastScanAt = $now;
        $this->databasePath = $databasePath;
        $this->sessionsPath = $sessionsPath;
        $this->schemaVersion = $schemaVersion;
        $this->observationCount = $observationCount;
        $this->reflectionCount = $reflectionCount;
        $this->sessionCount = $sessionCount;
        $this->sourceRevision = $sourceRevision;
        $this->sourceChangeCount = $sourceChangeCount;
        $this->sourceDetails = $sourceDetails;
        $this->touch($now);
    }
}
