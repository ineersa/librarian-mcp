<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Mcp\LibraryMetadataCorpus;
use App\Message\SyncLibraryMessage;
use App\Repository\LibraryRepository;
use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class LibraryManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LibraryRepository&MockObject $repository;
    private MessageBusInterface&MockObject $messageBus;
    private LibraryManager $manager;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(LibraryRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->manager = new LibraryManager(
            $this->em,
            $this->repository,
            $this->messageBus,
            '/app',
            'data',
            new LibraryMetadataCorpus($this->createMock(VeraCli::class), '/app', 'data', new NullLogger()),
        );
    }

    // --- deriveName ---

    public function testDeriveNameMainBranch(): void
    {
        $name = $this->manager->deriveName('https://github.com/symfony/symfony-docs', 'main');
        $this->assertSame('symfony/symfony-docs', $name);
    }

    public function testDeriveNameNonMainBranch(): void
    {
        $name = $this->manager->deriveName('https://github.com/symfony/symfony-docs', '6.4');
        $this->assertSame('symfony/symfony-docs@6.4', $name);
    }

    public function testDeriveNameWithGitSuffix(): void
    {
        $name = $this->manager->deriveName('https://github.com/symfony/symfony-docs.git', 'main');
        $this->assertSame('symfony/symfony-docs', $name);
    }

    // --- generateSlug ---

    public function testGenerateSlug(): void
    {
        $slug = $this->manager->generateSlug('symfony/symfony-docs@6.4');
        $this->assertSame('symfony/symfony-docs@6.4', $slug);
    }

    // --- computePath ---

    public function testComputePath(): void
    {
        $path = $this->manager->computePath('https://github.com/symfony/symfony-docs', 'main');
        $this->assertSame('symfony/symfony-docs/main', $path);
    }

    public function testComputePathWithGitSuffix(): void
    {
        $path = $this->manager->computePath('https://github.com/symfony/symfony-docs.git', '6.4');
        $this->assertSame('symfony/symfony-docs/6.4', $path);
    }

    // --- create ---

    public function testCreateSetsComputedFields(): void
    {
        $this->repository->method('findOneByPath')->willReturn(null);
        $this->repository->method('findOneBySlug')->willReturn(null);
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(static function (Library $lib) {
                // Simulate Doctrine assigning the ID
                $ref = new \ReflectionProperty($lib, 'id');
                $ref->setValue($lib, 1);
            });
        // create() flushes once, then markQueued() flushes once = 2 flush calls
        $this->em->expects($this->exactly(2))->method('flush');
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (SyncLibraryMessage $msg) => new Envelope($msg));

        $library = new Library();
        $library->setGitUrl('https://github.com/symfony/symfony-docs');
        $library->setBranch('main');
        $library->setDescription('Symfony docs');

        $this->manager->create($library);

        $this->assertSame('symfony/symfony-docs', $library->getName());
        $this->assertSame('symfony/symfony-docs', $library->getSlug());
        $this->assertSame('symfony/symfony-docs/main', $library->getPath());
        $this->assertSame(LibraryStatus::Queued, $library->getStatus());
    }

    public function testCreatePreservesUserOverrides(): void
    {
        $this->repository->method('findOneByPath')->willReturn(null);
        $this->repository->method('findOneBySlug')->willReturn(null);
        // create() flushes once, then markQueued() flushes once = 2 flush calls
        $this->em->expects($this->exactly(2))->method('flush');
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (SyncLibraryMessage $msg) => new Envelope($msg));

        $library = new Library();
        $library->setName('My Custom Name');
        $library->setSlug('custom-slug');
        $library->setGitUrl('https://github.com/symfony/symfony-docs');
        $library->setBranch('main');
        $library->setDescription('Symfony docs');

        // Simulate a persisted entity by setting the ID via reflection
        $ref = new \ReflectionProperty($library, 'id');
        $ref->setValue($library, 42);

        $this->manager->create($library);

        $this->assertSame('My Custom Name', $library->getName());
        $this->assertSame('custom-slug', $library->getSlug());
    }

    public function testCreateRejectsDuplicatePath(): void
    {
        $existing = new Library();
        $existing->setName('Existing Library');
        $existing->initializePath('symfony/symfony-docs/main');

        $this->repository->method('findOneByPath')->willReturn($existing);
        $this->repository->method('findOneBySlug')->willReturn(null);

        $library = new Library();
        $library->setGitUrl('https://github.com/symfony/symfony-docs');
        $library->setBranch('main');
        $library->setDescription('Duplicate');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already exists');
        $this->manager->create($library);
    }

    // --- markQueued ---

    public function testMarkQueuedDispatchesMessage(): void
    {
        $library = new Library();
        $library->setName('test');
        $library->setGitUrl('https://github.com/test/repo');
        $library->setBranch('main');
        $library->setDescription('Test');
        $library->initializePath('test/repo/main');

        // Simulate a persisted entity by setting the ID via reflection
        $ref = new \ReflectionProperty($library, 'id');
        $ref->setValue($library, 42);

        $this->em->expects($this->once())->method('flush');
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SyncLibraryMessage::class))
            ->willReturnCallback(static fn (SyncLibraryMessage $msg) => new Envelope($msg));

        $this->manager->markQueued($library);
        $this->assertSame(LibraryStatus::Queued, $library->getStatus());
    }

    // --- getAbsolutePath ---

    public function testGetAbsolutePath(): void
    {
        $library = new Library();
        $library->initializePath('symfony/symfony-docs/main');

        $this->assertSame('/app/data/libraries/symfony/symfony-docs/main', $this->manager->getAbsolutePath($library));
    }
}
