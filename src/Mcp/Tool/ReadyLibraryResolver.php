<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Library;
use App\Entity\LibraryStatus;
use App\Repository\LibraryRepository;

final readonly class ReadyLibraryResolver
{
    public function __construct(
        private LibraryRepository $libraryRepository,
    ) {
    }

    public function resolve(string $slug): Library
    {
        $library = $this->libraryRepository->findOneBySlug($slug);
        if (null === $library) {
            throw new ToolCallException('Library not found.', false, 'Use search-libraries to discover valid library slugs.');
        }

        if (LibraryStatus::Ready !== $library->getStatus()) {
            throw new ToolCallException('Library is not ready.', true, 'Wait for indexing to complete or trigger sync in admin.');
        }

        return $library;
    }
}
