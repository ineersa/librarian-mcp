<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Entity\Library;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardWidgetsTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'dashboard-admin@example.com';
    private const PASSWORD = 'test-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        foreach ($em->getRepository(Library::class)->findAll() as $library) {
            $em->remove($library);
        }
        foreach ($em->getRepository(User::class)->findAll() as $user) {
            $em->remove($user);
        }
        $em->flush();

        $admin = new User();
        $admin->email = self::ADMIN_EMAIL;
        $admin->roles = ['ROLE_ADMIN'];
        $admin->password = $hasher->hashPassword($admin, self::PASSWORD);
        $em->persist($admin);

        $readyLibrary = new Library();
        $readyLibrary->setName('symfony/symfony-docs');
        $readyLibrary->setSlug('symfony/symfony-docs@8.0');
        $readyLibrary->setGitUrl('https://github.com/symfony/symfony-docs');
        $readyLibrary->setBranch('8.0');
        $readyLibrary->setDescription('Symfony docs');
        $readyLibrary->initializePath('symfony/symfony-docs/8.0');

        $readyLibrary->markQueued();
        $readyLibrary->syncStarted();
        $readyLibrary->syncSucceeded();

        $em->persist($readyLibrary);
        $em->flush();

        self::ensureKernelShutdown();
    }

    public function testDashboardShowsAllMcpWidgetsWithHintsAndSelects(): void
    {
        $client = static::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent() ?: '';
        $this->assertStringContainsString('search-libraries', $content);
        $this->assertStringContainsString('semantic-search', $content);
        $this->assertStringContainsString('read', $content);
        $this->assertStringContainsString('grep', $content);

        $this->assertStringContainsString('Natural-language query', $content);
        $this->assertStringContainsString('Optional symbol-type filter', $content);
        $this->assertStringContainsString('Context lines before/after each match', $content);

        $this->assertGreaterThan(0, $crawler->filter('select[name="semantic_search[type]"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('select[name="semantic_search[scope]"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('select[name="grep[scope]"]')->count());

        // Ready library should be available in select widgets.
        $this->assertStringContainsString('symfony/symfony-docs@8.0', $content);
    }
}
