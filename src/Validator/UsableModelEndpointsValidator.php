<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\LibrarianSettings;
use App\Index\Model\EmbeddingEndpointGuard;
use App\Index\Model\ModelEndpoint;
use App\Index\Model\ModelEndpointMode;
use App\Index\Model\ModelEndpoints;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Probes only endpoints that change, so saving other settings never depends on model servers.
 */
final class UsableModelEndpointsValidator extends ConstraintValidator
{
    private const string EMBEDDINGS = 'embeddings';
    private const string RERANKER = 'reranker';

    public function __construct(
        private readonly ModelEndpoints $endpoints,
        private readonly EmbeddingEndpointGuard $guard,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UsableModelEndpoints) {
            throw new UnexpectedTypeException($constraint, UsableModelEndpoints::class);
        }
        if (!$value instanceof LibrarianSettings) {
            throw new UnexpectedValueException($value, LibrarianSettings::class);
        }

        /** @var array<string, mixed> $original */
        $original = null === $value->id ? [] : $this->entityManager->getUnitOfWork()->getOriginalEntityData($value);

        $embedding = $this->endpoints->embeddingFor($value);
        $previousEmbedding = $this->endpoints->resolveEmbedding(...self::originalEndpoint($original, 'embedding'));
        if (!$embedding->sameTarget($previousEmbedding)) {
            $path = ModelEndpointMode::BuiltIn === $value->embeddingMode ? 'embeddingMode' : 'embeddingApiUrl';
            if (!$this->endpoints->responds($embedding)) {
                $this->unreachable($constraint, $embedding, self::EMBEDDINGS, $path);
            } elseif (null !== $reason = $this->guard->incompatibility($embedding)) {
                $this->context->buildViolation($reason)->atPath($path)->addViolation();
            }
        }

        $reranker = $this->endpoints->rerankerFor($value);
        $previousReranker = $this->endpoints->resolveReranker(...self::originalEndpoint($original, 'reranker'));
        if (!$reranker->sameTarget($previousReranker) && !$this->endpoints->responds($reranker)) {
            $this->unreachable($constraint, $reranker, self::RERANKER, ModelEndpointMode::BuiltIn === $value->rerankerMode ? 'rerankerMode' : 'rerankerApiUrl');
        }
    }

    private function unreachable(UsableModelEndpoints $constraint, ModelEndpoint $endpoint, string $model, string $path): void
    {
        $this->context->buildViolation($constraint->unreachableMessage)
            ->setParameter('{{ model }}', $model)
            ->setParameter('{{ url }}', $endpoint->url)
            ->atPath($path)
            ->addViolation();
    }

    /**
     * @param array<string, mixed> $original
     *
     * @return array{ModelEndpointMode, ?string, ?string}
     */
    private static function originalEndpoint(array $original, string $prefix): array
    {
        $mode = $original[$prefix.'Mode'] ?? ModelEndpointMode::Api;
        $url = $original[$prefix.'ApiUrl'] ?? null;
        $model = $original[$prefix.'ApiModel'] ?? null;

        return [
            $mode instanceof ModelEndpointMode ? $mode : ModelEndpointMode::Api,
            \is_string($url) ? $url : null,
            \is_string($model) ? $model : null,
        ];
    }
}
