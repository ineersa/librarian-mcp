<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ProjectDirectoryController extends AbstractController
{
    private const int DIRECTORY_LIST_LIMIT = 200;

    public function __construct(
        #[Autowire('%env(PROJECT_BROWSE_ROOT)%')]
        private readonly string $browseRoot,
    ) {
    }

    #[Route('/admin/project-directories', name: 'admin_project_directories', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $browseRoot = $this->resolveBrowseRoot();
        if (null === $browseRoot) {
            return $this->json([
                'error' => $this->mountUnavailableMessage(),
                'path' => '',
                'parent' => null,
                'entries' => [],
                'truncated' => false,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $requestedPath = trim((string) $request->query->get('path', ''));
        if ('' === $requestedPath) {
            $requestedPath = $browseRoot;
        }

        try {
            $currentPath = $this->canonicalizeExistingDirectory($requestedPath);
        } catch (\InvalidArgumentException $exception) {
            $browseListing = $this->listDirectories($browseRoot);

            return $this->json([
                'error' => $exception->getMessage(),
                'path' => $browseRoot,
                'parent' => $this->parentDirectory($browseRoot),
                'entries' => $browseListing['entries'],
                'truncated' => $browseListing['truncated'],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $listing = $this->listDirectories($currentPath);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'error' => $exception->getMessage(),
                'path' => $currentPath,
                'parent' => $this->parentDirectory($currentPath),
                'entries' => [],
                'truncated' => false,
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'path' => $currentPath,
            'parent' => $this->parentDirectory($currentPath),
            'entries' => $listing['entries'],
            'truncated' => $listing['truncated'],
            'error' => null,
        ]);
    }

    private function resolveBrowseRoot(): ?string
    {
        $configured = trim($this->browseRoot);
        if ('' === $configured) {
            return null;
        }

        try {
            return $this->canonicalizeExistingDirectory($configured);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function canonicalizeExistingDirectory(string $path): string
    {
        if ('' === trim($path)) {
            throw new \InvalidArgumentException('Directory path is required.');
        }

        $canonical = Path::canonicalize($path);
        if (!Path::isAbsolute($canonical)) {
            throw new \InvalidArgumentException(\sprintf('Directory path must be absolute: %s', $path));
        }

        $realPath = realpath($canonical);
        if (false === $realPath) {
            throw new \InvalidArgumentException(\sprintf('Directory is not available: %s', $canonical));
        }

        if (!is_dir($realPath)) {
            throw new \InvalidArgumentException(\sprintf('Path is not a directory: %s', $realPath));
        }

        if (!is_readable($realPath)) {
            throw new \InvalidArgumentException(\sprintf('Directory is not readable: %s', $realPath));
        }

        return $realPath;
    }

    private function parentDirectory(string $path): ?string
    {
        $parent = Path::getDirectory($path);
        if ($parent === $path) {
            return null;
        }

        $realParent = realpath($parent);
        if (false === $realParent || !is_dir($realParent) || !is_readable($realParent)) {
            return null;
        }

        return $realParent;
    }

    /**
     * @return array{entries: list<array{name: string, path: string}>, truncated: bool}
     */
    private function listDirectories(string $path): array
    {
        $entries = [];
        $truncated = false;

        try {
            $finder = (new Finder())
                ->directories()
                ->depth('== 0')
                ->ignoreDotFiles(false)
                ->sortByName()
                ->in($path);

            foreach ($finder as $directory) {
                if (\count($entries) >= self::DIRECTORY_LIST_LIMIT) {
                    $truncated = true;
                    break;
                }

                $realPath = $directory->getRealPath();
                if (false === $realPath || !is_readable($realPath)) {
                    continue;
                }

                $entries[] = [
                    'name' => $directory->getFilename(),
                    'path' => $realPath,
                ];
            }
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(\sprintf('Unable to list directories in %s: %s', $path, $exception->getMessage()), previous: $exception);
        }

        return [
            'entries' => $entries,
            'truncated' => $truncated,
        ];
    }

    private function mountUnavailableMessage(): string
    {
        return \sprintf(
            'Project browse root is unavailable (%s). Mount the host directory into the service and set PROJECT_BROWSE_ROOT to that service-visible path.',
            '' !== trim($this->browseRoot) ? $this->browseRoot : 'empty PROJECT_BROWSE_ROOT',
        );
    }
}
