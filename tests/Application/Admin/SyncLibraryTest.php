<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Entity\User;
use App\Message\SyncLibraryMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

final class SyncLibraryTest extends WebTestCase
{
    use InteractsWithMessenger;

    private const ADMIN_EMAIL = 'sync-admin@example.com';
    private const PASSWORD = 'test-password-123';
    private const FIXTURE_REPO_URL = 'https://github.com/zenstruck/messenger-test';
    private const FIXTURE_BRANCH = '1.x';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // Purge libraries and users from previous test runs
        foreach ($this->em->getRepository(Library::class)->findAll() as $library) {
            $this->em->remove($library);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();

        // Create admin user
        $admin = new User();
        $admin->email = self::ADMIN_EMAIL;
        $admin->roles = ['ROLE_ADMIN'];
        $admin->password = $hasher->hashPassword($admin, self::PASSWORD);
        $this->em->persist($admin);

        $this->em->flush();
        self::ensureKernelShutdown();
    }

    /**
     * Full E2E: create library → message dispatched → process → Ready.
     *
     * This test clones a real GitHub repo (zenstruck/messenger-test — small, public)
     * and runs vera index on it.
     */
    public function testHappyPathSync(): void
    {
        $client = static::createClient();

        // Create library via LibraryManager (which auto-dispatches SyncLibraryMessage)
        $library = $this->createLibrary(
            gitUrl: self::FIXTURE_REPO_URL,
            branch: self::FIXTURE_BRANCH,
        );

        // Assert message was dispatched to the async transport
        $transport = $this->transport('async');
        $transport->queue()->assertContains(SyncLibraryMessage::class, 1);

        // Verify the dispatched message has the correct libraryId
        $messages = $transport->queue()->messages(SyncLibraryMessage::class);
        $this->assertCount(1, $messages);
        $this->assertSame($library->getId(), $messages[0]->libraryId);

        // Process the message — this runs the handler (clone + vera index)
        $transport->process();

        // Assert the queue is now empty (message was acked)
        $transport->queue()->assertEmpty();

        // Reload library and assert status
        $this->em->clear();
        $refreshed = $this->em->getRepository(Library::class)->find($library->getId());

        $this->assertNotNull($refreshed);
        $this->assertSame(LibraryStatus::Ready, $refreshed->getStatus());
        $this->assertNotNull($refreshed->getLastSyncedAt());
        $this->assertNotNull($refreshed->getLastIndexedAt());
        $this->assertNull($refreshed->getLastError());

        // Assert repo exists on disk
        $absPath = self::getContainer()->getParameter('kernel.project_dir').'/data/libraries/zenstruck/messenger-test/1.x';
        $this->assertDirectoryExists($absPath.'/.git');
    }

    /**
     * Failure path: invalid git URL → process → Failed status.
     */
    public function testFailurePathInvalidGitUrl(): void
    {
        $client = static::createClient();

        $library = $this->createLibrary(
            gitUrl: 'https://github.com/nonexistent/repo-that-does-not-exist-xyz',
            branch: 'main',
        );

        $transport = $this->transport('async');
        $transport->queue()->assertContains(SyncLibraryMessage::class, 1);

        // Process the message — handler should catch git clone failure
        $transport->process();

        // Assert queue is empty (message was rejected as unrecoverable)
        $transport->queue()->assertEmpty();

        // Reload library and assert Failed status
        $this->em->clear();
        $refreshed = $this->em->getRepository(Library::class)->find($library->getId());

        $this->assertNotNull($refreshed);
        $this->assertSame(LibraryStatus::Failed, $refreshed->getStatus());
        $this->assertNotNull($refreshed->getLastError());
        $this->assertStringContainsString('git', $refreshed->getLastError());
    }

    /**
     * Test that create() auto-dispatches SyncLibraryMessage.
     */
    public function testCreateAutoDispatchesMessage(): void
    {
        $client = static::createClient();

        $library = $this->createLibrary(
            gitUrl: self::FIXTURE_REPO_URL,
            branch: self::FIXTURE_BRANCH,
        );

        // Library should be in Queued status (set by markQueued in create())
        $this->assertSame(LibraryStatus::Queued, $library->getStatus());

        // Message should be on the transport
        $transport = $this->transport('async');
        $transport->queue()->assertContains(SyncLibraryMessage::class, 1);
    }

    /**
     * Concurrent sync guard: handler skips if library is not in Queued status.
     */
    public function testConcurrentGuardSkipsNonQueuedLibrary(): void
    {
        $client = static::createClient();

        // Create a library and manually set it to Indexing (bypass transitions)
        $library = new Library();
        $library->setName('test/concurrent');
        $library->setSlug('test-concurrent');
        $library->setGitUrl('https://github.com/test/concurrent');
        $library->setBranch('main');
        $library->setDescription('Concurrent test');
        $library->initializePath('test/concurrent/main');
        $library->markQueued();
        $library->syncStarted(); // Now in Indexing
        $this->em->persist($library);
        $this->em->flush();

        // Manually dispatch a message for this library
        self::getContainer()->get('messenger.bus.default')->dispatch(
            new SyncLibraryMessage($library->getId()),
        );

        $transport = $this->transport('async');
        $transport->queue()->assertContains(SyncLibraryMessage::class, 1);

        // Process — handler should skip because status is Indexing
        $transport->process();

        // Reload — status should still be Indexing (unchanged)
        $this->em->clear();
        $refreshed = $this->em->getRepository(Library::class)->find($library->getId());

        $this->assertSame(LibraryStatus::Indexing, $refreshed->getStatus());
    }

    private function createLibrary(
        string $gitUrl = self::FIXTURE_REPO_URL,
        string $branch = self::FIXTURE_BRANCH,
    ): Library {
        // Use LibraryManager to create (which auto-dispatches)
        $manager = self::getContainer()->get(\App\Service\LibraryManager::class);

        $library = new Library();
        $library->setGitUrl($gitUrl);
        $library->setBranch($branch);
        $library->setDescription('Test library for sync');

        $manager->create($library);

        return $library;
    }
}
