<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Entity\Library;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LibraryCrudTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'lib-admin@example.com';
    private const PASSWORD = 'test-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // Purge libraries and users from previous test runs
        foreach ($em->getRepository(Library::class)->findAll() as $library) {
            $em->remove($library);
        }
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

    public function testLibraryIndexPageLoads(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Libraries');
    }

    public function testLibraryCreatePageLoads(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testLibraryDetailPageLoads(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        // Create a library directly
        $library = new Library();
        $library->setName('test/library');
        $library->setSlug('test-library');
        $library->setGitUrl('https://github.com/test/library');
        $library->setBranch('main');
        $library->setDescription('A test library');
        $library->initializePath('test/library/main');
        $em->persist($library);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries/'.$library->getId());

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('test/library', $client->getResponse()->getContent());
    }

    public function testLibraryIndexListsExistingLibrary(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        // Create a library directly
        $library = new Library();
        $library->setName('symfony/symfony-docs');
        $library->setSlug('symfony-symfony-docs');
        $library->setGitUrl('https://github.com/symfony/symfony-docs');
        $library->setBranch('main');
        $library->setDescription('Symfony documentation');
        $library->initializePath('symfony/symfony-docs/main');
        $em->persist($library);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/libraries');

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('symfony/symfony-docs', $crawler->filter('table')->text());
    }

    public function testLibraryCrudRequiresAdminAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/libraries');

        // Should redirect to login
        self::assertResponseRedirects('/login');
    }

    public function testSyncNowActionSetsStatusToQueued(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $library = new Library();
        $library->setName('test/sync-lib');
        $library->setSlug('test-sync-lib');
        $library->setGitUrl('https://github.com/test/sync-lib');
        $library->setBranch('main');
        $library->setDescription('A sync test library');
        $library->initializePath('test/sync-lib/main');
        $em->persist($library);
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries/'.$library->getId().'/sync');

        self::assertResponseRedirects();

        // Reload and check status
        $refreshed = $em->getRepository(Library::class)->find($library->getId());
        $this->assertSame(\App\Entity\LibraryStatus::Queued, $refreshed->getStatus());
    }

    public function testSyncNowOnFailedLibrarySetsStatusToQueued(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $library = new Library();
        $library->setName('test/failed-lib');
        $library->setSlug('test-failed-lib');
        $library->setGitUrl('https://github.com/test/failed-lib');
        $library->setBranch('main');
        $library->setDescription('A failed library');
        $library->initializePath('test/failed-lib/main');
        $em->persist($library);
        $em->flush();

        // Transition to indexing then failed
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('Something went wrong');
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries/'.$library->getId().'/sync');

        self::assertResponseRedirects();

        $refreshed = $em->getRepository(Library::class)->find($library->getId());
        $this->assertSame(\App\Entity\LibraryStatus::Queued, $refreshed->getStatus());
    }

    public function testSyncNowOnReadyLibrarySetsStatusToQueued(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $library = new Library();
        $library->setName('test/ready-lib');
        $library->setSlug('test-ready-lib');
        $library->setGitUrl('https://github.com/test/ready-lib');
        $library->setBranch('main');
        $library->setDescription('A ready library');
        $library->initializePath('test/ready-lib/main');
        $em->persist($library);
        $em->flush();

        // Transition to ready
        $library->markQueued();
        $library->syncStarted();
        $library->syncSucceeded();
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request('GET', '/admin/libraries/'.$library->getId().'/sync');

        self::assertResponseRedirects();

        $refreshed = $em->getRepository(Library::class)->find($library->getId());
        $this->assertSame(\App\Entity\LibraryStatus::Queued, $refreshed->getStatus());
    }

    public function testLibrarySearchByName(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        $lib1 = new Library();
        $lib1->setName('symfony/symfony-docs');
        $lib1->setSlug('symfony-symfony-docs');
        $lib1->setGitUrl('https://github.com/symfony/symfony-docs');
        $lib1->setBranch('main');
        $lib1->setDescription('Symfony docs');
        $lib1->initializePath('symfony/symfony-docs/main');
        $em->persist($lib1);

        $lib2 = new Library();
        $lib2->setName('laravel/docs');
        $lib2->setSlug('laravel-docs');
        $lib2->setGitUrl('https://github.com/laravel/docs');
        $lib2->setBranch('main');
        $lib2->setDescription('Laravel docs');
        $lib2->initializePath('laravel/docs/main');
        $em->persist($lib2);

        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/libraries?query=symfony');

        self::assertResponseIsSuccessful();
        $tableText = $crawler->filter('table')->text();
        $this->assertStringContainsString('symfony/symfony-docs', $tableText);
        $this->assertStringNotContainsString('laravel/docs', $tableText);
    }
}
