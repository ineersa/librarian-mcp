<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LibraryRepository;
use App\Vera\VeraIndexingConfig;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LibraryRepository::class)]
#[ORM\Table(name: 'libraries')]
#[ORM\UniqueConstraint(name: 'uniq_library_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_library_path', columns: ['path'])]
class Library
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(type: 'string', length: 255)]
    private string $name = '';

    #[Assert\Length(max: 255)]
    #[ORM\Column(type: 'string', length: 255)]
    private string $slug = '';

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '~^https://github\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+(\.git)?$~',
        message: 'Must be a valid GitHub HTTPS URL.'
    )]
    #[ORM\Column(type: 'string', length: 255)]
    private string $gitUrl = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(type: 'string', length: 255)]
    private string $branch = 'main';

    #[ORM\Column(type: 'string', length: 255)]
    private string $path = '';

    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\Column(type: 'string', length: 20, enumType: LibraryStatus::class)]
    private LibraryStatus $status = LibraryStatus::Draft;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', name: 'vera_config', nullable: true)]
    private ?array $veraConfigData = null;

    /** @var array<string, bool>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $readableFiles = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastIndexedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getGitUrl(): string
    {
        return $this->gitUrl;
    }

    public function setGitUrl(string $gitUrl): void
    {
        $this->gitUrl = $gitUrl;
    }

    public function getBranch(): string
    {
        return $this->branch;
    }

    public function setBranch(string $branch): void
    {
        $this->branch = $branch;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getStatus(): LibraryStatus
    {
        return $this->status;
    }

    public function getVeraConfig(): ?VeraIndexingConfig
    {
        return null !== $this->veraConfigData ? VeraIndexingConfig::fromArray($this->veraConfigData) : null;
    }

    public function setVeraConfig(?VeraIndexingConfig $config): void
    {
        $this->veraConfigData = $config?->toArray();
    }

    /** @return array<string, bool> */
    public function getReadableFiles(): array
    {
        return $this->readableFiles ?? [];
    }

    /** @param array<string, bool> $readableFiles */
    public function setReadableFiles(array $readableFiles): void
    {
        $this->readableFiles = $readableFiles;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function getLastIndexedAt(): ?\DateTimeImmutable
    {
        return $this->lastIndexedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Set the computed path (only used during creation).
     */
    public function initializePath(string $path): void
    {
        if ('' !== $this->path) {
            throw new \LogicException('Path is immutable after creation.');
        }
        $this->path = $path;
    }

    // --- Status transitions ---

    public function markQueued(): void
    {
        $this->assertTransition(LibraryStatus::Draft, LibraryStatus::Queued, LibraryStatus::Indexing, LibraryStatus::Failed, LibraryStatus::Ready);
        $this->status = LibraryStatus::Queued;
        $this->touch();
    }

    public function syncStarted(): void
    {
        $this->assertTransition(LibraryStatus::Queued);
        $this->status = LibraryStatus::Indexing;
        $this->lastError = null;
        $this->touch();
    }

    public function syncFailed(string $error): void
    {
        $this->assertTransition(LibraryStatus::Queued, LibraryStatus::Indexing);
        $this->status = LibraryStatus::Failed;
        $this->lastError = mb_substr($error, 0, 2000);
        $this->touch();
    }

    public function syncSucceeded(): void
    {
        $this->assertTransition(LibraryStatus::Indexing);
        $this->status = LibraryStatus::Ready;
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->lastIndexedAt = new \DateTimeImmutable();
        $this->lastError = null;
        $this->touch();
    }

    /**
     * @throws \LogicException if current status is not in the allowed set
     */
    private function assertTransition(LibraryStatus ...$allowed): void
    {
        if (!\in_array($this->status, $allowed, true)) {
            throw new \LogicException(\sprintf('Cannot transition from "%s" (allowed: %s).', $this->status->value, implode(', ', array_map(static fn (LibraryStatus $s) => $s->value, $allowed))));
        }
    }
}
