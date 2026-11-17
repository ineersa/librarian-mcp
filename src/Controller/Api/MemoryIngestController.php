<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Mcp\EnsureProject;
use App\Mcp\MemoryContextResolver;
use App\Mcp\MemoryToolException;
use App\Memory\MemoryErrorCode;
use App\ObservationalMemory\Ingest\MemoryIngestException;
use App\ObservationalMemory\Ingest\MemoryIngestService;
use App\ObservationalMemory\Ingest\MemoryObserveRequest;
use App\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plan 09 contract v1: content-free observe signal, session projection and session deletion.
 * Not an MCP tool: models can never write memory.
 *
 * Project selection uses `X-Librarian-Project` with MCP canonicalization; observe
 * registers an unknown project exactly like `ensure_project`.
 */
#[Route('/api/om/v1', format: 'json')]
final class MemoryIngestController
{
    public const string STATUS_QUEUED = 'queued';
    private const string SESSION_ID_REQUIREMENT = '[^/\x00-\x1F]{1,255}';

    public function __construct(
        private readonly MemoryContextResolver $contextResolver,
        private readonly EnsureProject $ensureProject,
        private readonly ProjectRepository $projects,
        private readonly MemoryIngestService $ingest,
    ) {
    }

    #[Route('/observe', name: 'api_om_observe', methods: ['POST'])]
    public function observe(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            // Validate first: a rejected body must not register a project as a side effect.
            $observe = MemoryObserveRequest::fromJson($request->getContent());
            $this->ingest->observe($this->ensuredProject(), $observe);

            return new JsonResponse([
                'ok' => true,
                'contract_version' => MemoryObserveRequest::CONTRACT_VERSION,
                'status' => self::STATUS_QUEUED,
            ], Response::HTTP_ACCEPTED);
        });
    }

    #[Route('/sessions/{sourceKind}/{sessionId}/projection', name: 'api_om_projection', requirements: ['sessionId' => self::SESSION_ID_REQUIREMENT], methods: ['GET'])]
    public function projection(MemorySourceKind $sourceKind, string $sessionId): JsonResponse
    {
        return $this->guard(function () use ($sourceKind, $sessionId): JsonResponse {
            $project = $this->existingProject();

            return new JsonResponse([
                'ok' => true,
                'contract_version' => MemoryObserveRequest::CONTRACT_VERSION,
                'text' => null === $project ? '' : $this->ingest->projection($project, $sourceKind, $sessionId),
            ]);
        });
    }

    /**
     * The client permanently deleted this session: forget its memory. Never registers a
     * project; an unknown project or session is already deleted (idempotent 200).
     */
    #[Route('/sessions/{sourceKind}/{sessionId}', name: 'api_om_session_delete', requirements: ['sessionId' => self::SESSION_ID_REQUIREMENT], methods: ['DELETE'])]
    public function deleteSession(MemorySourceKind $sourceKind, string $sessionId): JsonResponse
    {
        return $this->guard(function () use ($sourceKind, $sessionId): JsonResponse {
            $project = $this->existingProject();

            return new JsonResponse([
                'ok' => true,
                'contract_version' => MemoryObserveRequest::CONTRACT_VERSION,
                'deleted' => null !== $project && $this->ingest->deleteSession($project, $sourceKind, $sessionId),
            ]);
        });
    }

    private function existingProject(): ?Project
    {
        return $this->projects->findOneBy(['rootCanonical' => $this->contextResolver->canonicalRoot()]);
    }

    private function ensuredProject(): Project
    {
        $canonicalRoot = $this->contextResolver->canonicalRoot();
        $this->ensureProject->ensure($canonicalRoot);

        return $this->projects->findOneBy(['rootCanonical' => $canonicalRoot])
            ?? throw new MemoryToolException(MemoryErrorCode::ProjectRegistrationUnavailable, 'Project registration is temporarily unavailable. Retry later.');
    }

    /** @param \Closure(): JsonResponse $action */
    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (MemoryIngestException $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $e->errorCode->value,
                'message' => $e->getMessage(),
            ], $e->errorCode->httpStatus());
        } catch (MemoryToolException $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $e->errorCode->value,
                'message' => $e->getMessage(),
                'retryable' => $e->errorCode->isRetryable(),
            ], $e->errorCode->isRetryable() ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
