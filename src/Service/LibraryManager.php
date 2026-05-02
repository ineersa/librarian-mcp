<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Library;
use App\Mcp\LibraryMetadataCorpus;
use App\Message\SyncLibraryMessage;
use App\Repository\LibraryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * All business logic for Library entities.
 * Controllers are thin delegates to this service.
 */
class LibraryManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LibraryRepository $repository,
        private readonly MessageBusInterface $messageBus,
        private readonly string $projectDir,
        private readonly string $libraryDataDir,
        private readonly LibraryMetadataCorpus $metadataCorpus,
    ) {
    }

    /**
     * Derive a library identifier (and default name) from gitUrl + branch.
     */
    public function deriveName(string $gitUrl, string $branch): string
    {
        $ownerRepo = $this->parseOwnerRepo($gitUrl);

        return 'main' === mb_strtolower($branch) ? $ownerRepo : \sprintf('%s@%s', $ownerRepo, $branch);
    }

    /**
     * Generate a slug from a name.
     */
    public function generateSlug(string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        return $normalized;
    }

    /**
     * Compute the relative storage path from gitUrl + branch.
     */
    public function computePath(string $gitUrl, string $branch): string
    {
        $ownerRepo = $this->parseOwnerRepo($gitUrl);

        return \sprintf('%s/%s', $ownerRepo, $branch);
    }

    /**
     * Resolve the absolute filesystem path for a library.
     */
    public function getAbsolutePath(Library $library): string
    {
        if ('' === trim($this->projectDir)) {
            throw new \LogicException('Project dir is empty, abort!');
        }

        return rtrim($this->projectDir, '/').'/'.$this->libraryDataDir.'/libraries/'.$library->getPath();
    }

    /**
     * Persist a new library entity with all computed fields.
     *
     * @throws \LogicException if path is not unique
     */
    public function create(Library $library): void
    {
        // Compute path if not set
        if ('' === $library->getPath()) {
            $path = $this->computePath($library->getGitUrl(), $library->getBranch());
            $library->initializePath($path);
        }

        // Derive name if empty
        if ('' === $library->getName()) {
            $library->setName($this->deriveName($library->getGitUrl(), $library->getBranch()));
        }

        // Generate slug if empty
        if ('' === $library->getSlug()) {
            $library->setSlug($this->generateSlug($library->getName()));
        }

        // Ensure slug uniqueness — reject if taken by another library
        $this->assertSlugIsUnique($library);

        // Pre-persist validation: unique path
        $existing = $this->repository->findOneByPath($library->getPath());
        if (null !== $existing) {
            throw new \LogicException(\sprintf('Repository "%s" with branch "%s" already exists as library "%s".', $this->parseOwnerRepo($library->getGitUrl()), $library->getBranch(), $existing->getName()));
        }

        $library->touch();
        $this->em->persist($library);
        $this->em->flush();

        $this->metadataCorpus->upsert($library);

        // Auto-dispatch sync after creation
        $this->markQueued($library);
    }

    /**
     * Update an existing library.
     */
    public function update(Library $library): void
    {
        // Ensure slug uniqueness — reject if taken by another library
        $this->assertSlugIsUnique($library);

        $library->touch();
        $this->em->flush();

        $this->metadataCorpus->upsert($library);
    }

    /**
     * Delete a library: remove filesystem directory + DB record.
     */
    public function delete(Library $library): void
    {
        $absolutePath = $this->getAbsolutePath($library);

        if (is_dir($absolutePath)) {
            $this->removeDirectory($absolutePath);
        }

        $this->metadataCorpus->remove($library);

        $this->em->remove($library);
        $this->em->flush();
    }

    /**
     * Mark a library as queued and dispatch SyncLibraryMessage.
     */
    public function markQueued(Library $library): void
    {
        $library->markQueued();
        $library->touch();
        $this->em->flush();

        $this->messageBus->dispatch(new SyncLibraryMessage($library->getId()));
    }

    /**
     * Prepare the clone directory: remove existing contents and ensure parent dirs exist.
     */
    public function prepareCloneDirectory(string $absolutePath): void
    {
        if (is_dir($absolutePath)) {
            $this->removeDirectory($absolutePath);
        }

        $parentDir = \dirname($absolutePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
    }

    /**
     * Parse owner/repo from a GitHub HTTPS URL.
     */
    private function parseOwnerRepo(string $gitUrl): string
    {
        $url = preg_replace('~\.git$~', '', $gitUrl);

        if (!preg_match('~^https://github\.com/([a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+)$~', $url, $matches)) {
            throw new \InvalidArgumentException(\sprintf('Cannot parse owner/repo from URL: "%s".', $gitUrl));
        }

        return mb_strtolower($matches[1]);
    }

    /**
     * Assert that the slug is unique. Throws if another library already uses it.
     */
    private function assertSlugIsUnique(Library $library): void
    {
        $existing = $this->repository->findOneBySlug($library->getSlug());
        if (null !== $existing && $existing->getId() !== $library->getId()) {
            throw new \LogicException(\sprintf('Slug "%s" is already used by library "%s".', $library->getSlug(), $existing->getName()));
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $path): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }
}
