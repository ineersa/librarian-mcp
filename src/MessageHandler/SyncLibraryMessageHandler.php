<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Library;
use App\Mcp\LibraryMetadataCorpus;
use App\Message\SyncLibraryMessage;
use App\Repository\LibraryRepository;
use App\Service\LibraryManager;
use App\Vera\VeraCli;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class SyncLibraryMessageHandler
{
    private const MERCURE_TOPIC = 'https://librarian-mcp.local/topics/libraries';

    public function __construct(
        private readonly LibraryRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LibraryManager $libraryManager,
        private readonly VeraCli $veraCli,
        private readonly HubInterface $mercureHub,
        private readonly LibraryMetadataCorpus $metadataCorpus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncLibraryMessage $message): void
    {
        $syncStartedAt = microtime(true);

        $library = $this->repository->find($message->libraryId);
        if (null === $library) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Library %d not found.', $message->libraryId));
        }

        $this->logger->info('Library sync handler started.', [
            'libraryId' => $library->getId(),
            'librarySlug' => $library->getSlug(),
            'libraryPath' => $library->getPath(),
            'status' => $library->getStatus()->value,
        ]);

        // Concurrent sync guard: skip if not in Queued status
        if (!$library->getStatus()->isQueued()) {
            $this->logger->info('Skipping sync message because library is not queued.', [
                'libraryId' => $library->getId(),
                'status' => $library->getStatus()->value,
            ]);

            return;
        }

        try {
            // Queued → Indexing
            $library->syncStarted();
            $this->em->flush();
            $this->publishStatus($library);

            $absolutePath = $this->libraryManager->getAbsolutePath($library);

            $this->logger->info('Sync phase starting: prepare clone directory.', [
                'libraryId' => $library->getId(),
                'absolutePath' => $absolutePath,
            ]);
            $phaseStartedAt = microtime(true);
            $this->libraryManager->prepareCloneDirectory($absolutePath);
            $this->logger->info('Sync phase finished: prepare clone directory.', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);

            $this->logger->info('Sync phase starting: git clone.', [
                'libraryId' => $library->getId(),
                'gitUrl' => $library->getGitUrl(),
                'branch' => $library->getBranch(),
                'absolutePath' => $absolutePath,
            ]);
            $phaseStartedAt = microtime(true);
            $this->veraCli->cloneRepository($absolutePath, $library->getGitUrl(), $library->getBranch());
            $this->logger->info('Sync phase finished: git clone.', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);

            $this->logger->info('Sync phase starting: vera index.', [
                'libraryId' => $library->getId(),
                'absolutePath' => $absolutePath,
            ]);
            $phaseStartedAt = microtime(true);
            $veraConfig = $library->getVeraConfig();
            $this->veraCli->indexLibrary($absolutePath, $veraConfig ?? new \App\Vera\VeraIndexingConfig());
            $this->logger->info('Sync phase finished: vera index.', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);

            $this->logger->info('Sync phase starting: build readable files manifest.', [
                'libraryId' => $library->getId(),
                'absolutePath' => $absolutePath,
            ]);
            $phaseStartedAt = microtime(true);
            $readableFiles = $this->buildReadableFilesManifest($absolutePath, $library->getId() ?? 0);
            $library->setReadableFiles($readableFiles);
            $this->logger->info('Sync phase finished: build readable files manifest.', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
                'readableFilesCount' => \count($readableFiles),
            ]);

            // Indexing → Ready
            $library->syncSucceeded();
            $this->em->flush();

            $this->logger->info('Sync phase starting: metadata corpus upsert (ready).', [
                'libraryId' => $library->getId(),
            ]);
            $phaseStartedAt = microtime(true);
            $this->metadataCorpus->upsert($library);
            $this->logger->info('Sync phase finished: metadata corpus upsert (ready).', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);

            $this->publishStatus($library);

            $this->logger->info('Library sync handler finished successfully.', [
                'libraryId' => $library->getId(),
                'status' => $library->getStatus()->value,
                'durationMs' => (int) round((microtime(true) - $syncStartedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            // Indexing → Failed
            $library->syncFailed($e->getMessage());
            $this->em->flush();

            $this->logger->info('Sync phase starting: metadata corpus upsert (failed).', [
                'libraryId' => $library->getId(),
            ]);
            $phaseStartedAt = microtime(true);
            $this->metadataCorpus->upsert($library);
            $this->logger->info('Sync phase finished: metadata corpus upsert (failed).', [
                'libraryId' => $library->getId(),
                'durationMs' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);

            $this->publishStatus($library, $e->getMessage());

            $this->logger->error('Library sync handler failed.', [
                'libraryId' => $library->getId(),
                'status' => $library->getStatus()->value,
                'durationMs' => (int) round((microtime(true) - $syncStartedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    private function publishStatus(Library $library, ?string $error = null): void
    {
        $payload = [
            'libraryId' => $library->getId(),
            'status' => $library->getStatus()->value,
        ];

        if (null !== $error) {
            $payload['lastError'] = mb_substr($error, 0, 2000);
        }

        $json = json_encode($payload, \JSON_THROW_ON_ERROR);

        $this->logger->info('Publishing Mercure library status update.', [
            'topic' => self::MERCURE_TOPIC,
            'payload' => $payload,
        ]);

        $updateId = $this->mercureHub->publish(new Update(self::MERCURE_TOPIC, $json));

        $this->logger->info('Mercure library status update published.', [
            'topic' => self::MERCURE_TOPIC,
            'updateId' => $updateId,
            'libraryId' => $library->getId(),
            'status' => $library->getStatus()->value,
        ]);
    }

    /** @return array<string, bool> */
    private function buildReadableFilesManifest(string $absolutePath, int $libraryId): array
    {
        $startedAt = microtime(true);
        $manifest = [];
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);

        $scannedFiles = 0;
        $realPathFailures = 0;
        $nonTextFiles = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            ++$scannedFiles;

            if (0 === $scannedFiles % 1000) {
                $this->logger->info('Readable manifest progress.', [
                    'libraryId' => $libraryId,
                    'scannedFiles' => $scannedFiles,
                    'readableFiles' => \count($manifest),
                ]);
            }

            $realPath = $file->getRealPath();
            if (false === $realPath) {
                ++$realPathFailures;
                continue;
            }

            if (!$this->isTextFile($realPath, $finfo)) {
                ++$nonTextFiles;
                continue;
            }

            $relativePath = ltrim(str_replace(rtrim($absolutePath, '/').'/', '', str_replace('\\', '/', $realPath)), '/');
            $manifest[$relativePath] = true;
        }

        ksort($manifest);

        $this->logger->info('Readable manifest completed.', [
            'libraryId' => $libraryId,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'scannedFiles' => $scannedFiles,
            'readableFiles' => \count($manifest),
            'nonTextFiles' => $nonTextFiles,
            'realPathFailures' => $realPathFailures,
        ]);

        return $manifest;
    }

    private function isTextFile(string $path, \finfo $finfo): bool
    {
        $mime = $finfo->file($path);
        if (false === $mime) {
            return false;
        }

        return str_starts_with($mime, 'text/')
            || str_contains($mime, 'json')
            || str_contains($mime, 'xml')
            || str_contains($mime, 'javascript')
            || str_contains($mime, 'x-php')
            || 'inode/x-empty' === $mime;
    }
}
