<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    public string $email;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column(type: 'json')]
    public array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column(type: 'string')]
    public string $password = '';

    /**
     * Plain-text password for admin CRUD (never persisted).
     */
    public ?string $plainPassword = null;

    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    public private(set) ?string $mcpTokenHash = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $mcpTokenCreatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $mcpTokenLastUsedAt = null;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setMcpTokenHash(?string $mcpTokenHash): void
    {
        $this->mcpTokenHash = $mcpTokenHash;
    }

    public function setMcpTokenCreatedAt(?\DateTimeImmutable $mcpTokenCreatedAt): void
    {
        $this->mcpTokenCreatedAt = $mcpTokenCreatedAt;
    }

    public function setMcpTokenLastUsedAt(?\DateTimeImmutable $mcpTokenLastUsedAt): void
    {
        $this->mcpTokenLastUsedAt = $mcpTokenLastUsedAt;
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->getRoles(), true);
    }

    public function getMaskedMcpToken(): string
    {
        return null === $this->mcpTokenHash ? 'not set' : '*****';
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }
}
