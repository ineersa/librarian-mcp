<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Fidry\AliceDataFixtures\ProcessorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserPasswordProcessor implements ProcessorInterface
{
    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function preProcess(string $id, object $object): void
    {
        if ($object instanceof User) {
            $object->password = $this->hasher->hashPassword($object, $object->password);
        }
    }

    public function postProcess(string $id, object $object): void
    {
    }
}
