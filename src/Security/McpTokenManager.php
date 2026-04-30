<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final class McpTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function regenerate(User $user): string
    {
        $token = 'mcp_'.bin2hex(random_bytes(24));

        $user->setMcpTokenHash(self::hashToken($token));
        $user->setMcpTokenCreatedAt($this->clock->now());
        $user->setMcpTokenLastUsedAt(null);
        $user->touch();

        $this->em->flush();

        return $token;
    }

    public function touchLastUsed(User $user): void
    {
        $user->setMcpTokenLastUsedAt($this->clock->now());
        $user->touch();
        $this->em->flush();
    }

    public function findUserByToken(string $plainToken): ?User
    {
        if (!str_starts_with($plainToken, 'mcp_')) {
            return null;
        }

        return $this->userRepository->findOneByMcpTokenHash(self::hashToken($plainToken));
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
