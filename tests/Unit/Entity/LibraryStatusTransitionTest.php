<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use PHPUnit\Framework\TestCase;

final class LibraryStatusTransitionTest extends TestCase
{
    public function testInitialStateIsDraft(): void
    {
        $library = new Library();
        $this->assertSame(LibraryStatus::Draft, $library->getStatus());
    }

    // --- Allowed transitions ---

    public function testDraftToQueued(): void
    {
        $library = new Library();
        $library->markQueued();
        $this->assertSame(LibraryStatus::Queued, $library->getStatus());
    }

    public function testQueuedToIndexing(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $this->assertSame(LibraryStatus::Indexing, $library->getStatus());
    }

    public function testIndexingToReady(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncSucceeded();
        $this->assertSame(LibraryStatus::Ready, $library->getStatus());
        $this->assertNotNull($library->getLastSyncedAt());
        $this->assertNotNull($library->getLastIndexedAt());
    }

    public function testIndexingToFailed(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('out of disk');
        $this->assertSame(LibraryStatus::Failed, $library->getStatus());
        $this->assertSame('out of disk', $library->getLastError());
    }

    public function testFailedToQueued(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('timeout');
        $library->markQueued();
        $this->assertSame(LibraryStatus::Queued, $library->getStatus());
    }

    // --- Disallowed transitions ---

    public function testDraftCannotSyncStart(): void
    {
        $library = new Library();
        $this->expectException(\LogicException::class);
        $library->syncStarted();
    }

    public function testDraftCannotSyncSucceed(): void
    {
        $library = new Library();
        $this->expectException(\LogicException::class);
        $library->syncSucceeded();
    }

    public function testDraftCannotSyncFail(): void
    {
        $library = new Library();
        $this->expectException(\LogicException::class);
        $library->syncFailed('error');
    }

    public function testQueuedCannotSyncSucceed(): void
    {
        $library = new Library();
        $library->markQueued();
        $this->expectException(\LogicException::class);
        $library->syncSucceeded();
    }

    public function testQueuedCannotSyncFail(): void
    {
        $library = new Library();
        $library->markQueued();
        $this->expectException(\LogicException::class);
        $library->syncFailed('error');
    }

    public function testReadyCannotMarkQueued(): void
    {
        $library = $this->createReadyLibrary();
        $this->expectException(\LogicException::class);
        $library->markQueued();
    }

    public function testReadyCannotSyncStart(): void
    {
        $library = $this->createReadyLibrary();
        $this->expectException(\LogicException::class);
        $library->syncStarted();
    }

    public function testIndexingCannotMarkQueued(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $this->expectException(\LogicException::class);
        $library->markQueued();
    }

    public function testFailedCannotSyncStart(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('err');
        $this->expectException(\LogicException::class);
        $library->syncStarted();
    }

    // --- Domain behavior ---

    public function testSyncSucceededClearsLastError(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('some error');
        $this->assertSame('some error', $library->getLastError());

        $library->markQueued();
        $library->syncStarted();
        $library->syncSucceeded();
        $this->assertNull($library->getLastError());
    }

    public function testSyncFailedTruncatesErrorTo2000Chars(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();

        $longError = str_repeat('x', 3000);
        $library->syncFailed($longError);
        $this->assertSame(2000, \strlen($library->getLastError() ?? ''));
    }

    public function testSyncStartedClearsLastError(): void
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncFailed('bad');
        $library->markQueued();

        $library->syncStarted();
        $this->assertNull($library->getLastError());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $library = new Library();
        $before = $library->getUpdatedAt();
        usleep(1000); // 1ms
        $library->touch();
        $this->assertGreaterThan($before, $library->getUpdatedAt());
    }

    public function testInitializePathIsImmutable(): void
    {
        $library = new Library();
        $library->initializePath('owner/repo/branch');
        $this->expectException(\LogicException::class);
        $library->initializePath('other/repo/branch');
    }

    private function createReadyLibrary(): Library
    {
        $library = new Library();
        $library->markQueued();
        $library->syncStarted();
        $library->syncSucceeded();

        return $library;
    }
}
