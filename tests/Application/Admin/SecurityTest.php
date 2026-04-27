<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'test-admin@example.com';
    private const USER_EMAIL = 'test-user@example.com';
    private const PASSWORD = 'test-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        // Bootstrap the kernel to get services, but don't call createClient()
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

        // Create regular user (no ROLE_ADMIN)
        $user = new User();
        $user->email = self::USER_EMAIL;
        $user->roles = [];
        $user->password = $hasher->hashPassword($user, self::PASSWORD);
        $em->persist($user);

        $em->flush();

        // Shut down kernel so createClient() can boot a fresh one
        self::ensureKernelShutdown();
    }

    public function testAdminRedirectsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testAdminDeniesNonAdminUser(): void
    {
        $client = static::createClient();
        $user = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::USER_EMAIL]);

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminAllowsAdminUser(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testLoginFormRejectsBadCredentials(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $client->submitForm('Sign in', [
            '_username' => self::ADMIN_EMAIL,
            '_password' => 'wrong-password',
        ]);

        // Redirects back to login on failure
        self::assertResponseRedirects('/login');
        $client->followRedirect();

        self::assertSelectorTextContains('.text-red-300', 'Invalid credentials');
    }

    public function testLoginFormAcceptsValidCredentials(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $client->submitForm('Sign in', [
            '_username' => self::ADMIN_EMAIL,
            '_password' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/admin');
        $client->followRedirect();

        // Dashboard should load
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a', 'Librarian MCP');
    }

    public function testLoginPageRedirectsLoggedInAdmin(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/login');

        self::assertResponseRedirects('/admin');
    }

    public function testLogoutWorks(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);

        // Verify we're logged in
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        // Logout
        $client->request('GET', '/logout');

        // Symfony intercepts logout and redirects
        self::assertResponseRedirects();
    }
}
