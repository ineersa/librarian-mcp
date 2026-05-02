<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCrudTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'crud-admin@example.com';
    private const PASSWORD = 'test-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // Purge users from previous test runs
        foreach ($em->getRepository(User::class)->findAll() as $user) {
            $em->remove($user);
        }
        $em->flush();

        // Create admin user
        $admin = new User();
        $admin->email = self::ADMIN_EMAIL;
        $admin->roles = ['ROLE_ADMIN'];
        $admin->password = $hasher->hashPassword($admin, self::PASSWORD);
        $em->persist($admin);

        $em->flush();

        self::ensureKernelShutdown();
    }

    public function testUserIndexPageLoads(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Users');
    }

    public function testUserIndexListsExistingUser(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        // The admin user should appear in the table
        self::assertStringContainsString(self::ADMIN_EMAIL, $crawler->filter('table')->text());
    }

    public function testUserCreatePageLoads(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin/users/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testUserDetailPageLoads(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin/users/'.$admin->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::ADMIN_EMAIL, $client->getResponse()->getContent());
    }
}
