<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Project;
use App\Memory\MemoryErrorCode;
use App\Project\ProjectPathNormalizer;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/** Connection configuration is repeated on HTTP requests, never stored in an MCP session. */
final readonly class MemoryContextResolver
{
    public const string PROJECT_HEADER = 'X-Librarian-Project';
    public const string SESSION_HEADER = 'X-Hatfield-Session';
    private const int MAX_PROJECT_PATH_BYTES = 2048;

    public function __construct(
        private RequestStack $requestStack,
        private ProjectPathNormalizer $pathNormalizer,
        private ProjectRepository $projects,
    ) {
    }

    public function project(): Project
    {
        $path = $this->requestStack->getCurrentRequest()?->headers->get(self::PROJECT_HEADER);
        if (null === $path || '' === trim($path) || str_contains($path, "\0") || \strlen($path) > self::MAX_PROJECT_PATH_BYTES) {
            throw new MemoryToolException(MemoryErrorCode::InvalidProjectContext, 'Configure X-Librarian-Project with an absolute registered project root.');
        }
        $canonical = $this->pathNormalizer->canonicalizeRoot($path);
        if (null === $canonical) {
            throw new MemoryToolException(MemoryErrorCode::InvalidProjectContext, 'The configured project root must be an accessible absolute directory.');
        }
        $project = $this->projects->findOneBy(['rootCanonical' => $canonical]);
        if (null === $project) {
            throw new MemoryToolException(MemoryErrorCode::ProjectNotFound, 'The configured project root is not registered with Librarian.');
        }

        return $project;
    }

    public function currentSession(): string
    {
        $session = $this->requestStack->getCurrentRequest()?->headers->get(self::SESSION_HEADER);
        if (null === $session || '' === trim($session)) {
            throw new MemoryToolException(MemoryErrorCode::InvalidSessionContext, 'Configure X-Hatfield-Session with the current Hatfield OM session ID, or pass session_id to recall.');
        }

        return trim($session);
    }

    /** @return array{id: int|null, name: string, root: string} */
    public static function provenance(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'root' => $project->rootCanonical];
    }
}
