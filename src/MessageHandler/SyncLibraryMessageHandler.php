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
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class SyncLibraryMessageHandler
{
    private const MERCURE_TOPIC = 'libraries';

    public function __construct(
        private readonly LibraryRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LibraryManager $libraryManager,
        private readonly VeraCli $veraCli,
        private readonly HubInterface $mercureHub,
        private readonly LibraryMetadataCorpus $metadataCorpus,
    ) {
    }

    public function __invoke(SyncLibraryMessage $message): void
    {
        $library = $this->repository->find($message->libraryId);
        if (null === $library) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Library %d not found.', $message->libraryId));
        }

        // Concurrent sync guard: skip if not in Queued status
        if (!$library->getStatus()->isQueued()) {
            return;
        }

        try {
            // Queued → Indexing
            $library->syncStarted();
            $this->em->flush();
            $this->publishStatus($library);

            // Prepare clone directory (nuke existing + ensure parent dirs)
            $absolutePath = $this->libraryManager->getAbsolutePath($library);
            $this->libraryManager->prepareCloneDirectory($absolutePath);

            // Clone repository
            $this->veraCli->cloneRepository($absolutePath, $library->getGitUrl(), $library->getBranch());

            // Run vera index
            $veraConfig = $library->getVeraConfig();
            $this->veraCli->indexLibrary($absolutePath, $veraConfig ?? new \App\Vera\VeraIndexingConfig());

            $library->setReadableFiles($this->buildReadableFilesManifest($absolutePath));

            // Indexing → Ready
            $library->syncSucceeded();
            $this->em->flush();
            $this->metadataCorpus->upsert($library);
            $this->publishStatus($library);
        } catch (\Throwable $e) {
            // Indexing → Failed
            $library->syncFailed($e->getMessage());
            $this->em->flush();
            $this->metadataCorpus->upsert($library);
            $this->publishStatus($library, $e->getMessage());

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

        $this->mercureHub->publish(new Update(
            self::MERCURE_TOPIC,
            json_encode($payload, \JSON_THROW_ON_ERROR),
        ));
    }

    /** @return array<string, bool> */
    private function buildReadableFilesManifest(string $absolutePath): array
    {
        $manifest = [];
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolutePath, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (false === $realPath) {
                continue;
            }

            if (!$this->isTextFile($realPath, $finfo)) {
                continue;
            }

            $relativePath = ltrim(str_replace(rtrim($absolutePath, '/').'/', '', str_replace('\\', '/', $realPath)), '/');
            $manifest[$relativePath] = true;
        }

        ksort($manifest);

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
