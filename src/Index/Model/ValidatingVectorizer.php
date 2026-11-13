<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexException;
use App\Index\IndexSettings;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Platform\Vector\VectorInterface;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic-platform vectorizer with strict dimension/cardinality validation.
 */
final class ValidatingVectorizer implements VectorizerInterface
{
    private readonly Vectorizer $inner;
    private readonly PlatformInterface $platform;

    public function __construct(
        private readonly IndexSettings $settings,
        ?HttpClientInterface $httpClient = null,
        ?PlatformInterface $platform = null,
        ?Vectorizer $vectorizer = null,
    ) {
        $http = $httpClient ?? HttpClient::create([
            'timeout' => 30.0,
            'max_duration' => 30.0,
            'extra' => [
                'trace_content' => false,
            ],
        ]);
        $this->platform = $platform ?? GenericFactory::createPlatform(
            baseUrl: $this->settings->embeddingUrl,
            httpClient: $http,
            modelCatalog: new ModelCatalog([
                $this->settings->embeddingModel => [
                    'class' => EmbeddingsModel::class,
                    'capabilities' => [
                        Capability::INPUT_TEXT,
                        Capability::INPUT_MULTIPLE,
                        Capability::EMBEDDINGS,
                    ],
                ],
            ]),
            supportsCompletions: false,
            supportsEmbeddings: true,
            embeddingsPath: $this->embeddingsPath($this->settings->embeddingUrl),
            name: 'librarian-coderank',
        );
        $this->inner = $vectorizer ?? new Vectorizer(
            $this->platform,
            $this->settings->embeddingModel,
            new NullLogger(),
            true,
        );
    }

    public function platform(): PlatformInterface
    {
        return $this->platform;
    }

    public function vectorize(string|\Stringable|EmbeddableDocumentInterface|array $values, array $options = []): Vector|VectorDocumentInterface|array
    {
        try {
            $result = $this->inner->vectorize($values, $options);
        } catch (\Throwable $error) {
            throw IndexException::sanitized('Embedding request failed.', $error);
        }

        if ($result instanceof Vector) {
            $this->assertVector($result);

            return $result;
        }

        if ($result instanceof VectorDocumentInterface) {
            $this->assertVector($result->getVector());

            return $result;
        }

        if (!\is_array($result)) {
            throw IndexException::sanitized('Embedding endpoint returned an unexpected result type.');
        }

        $expected = $this->expectedCount($values);
        if (\count($result) !== $expected) {
            throw IndexException::sanitized('Embedding endpoint returned an invalid vector count.');
        }

        foreach ($result as $item) {
            if ($item instanceof VectorDocumentInterface) {
                $this->assertVector($item->getVector());
                continue;
            }
            if ($item instanceof VectorInterface) {
                $this->assertVector($item);
                continue;
            }

            throw IndexException::sanitized('Embedding endpoint returned an unexpected item type.');
        }

        return $result;
    }

    public function embedQuery(string $question): Vector
    {
        $prefixed = IndexSettings::QUERY_PREFIX.$question;
        $vector = $this->vectorize($prefixed);
        if (!$vector instanceof Vector) {
            throw IndexException::sanitized('Query embedding did not return a single vector.');
        }

        return $vector;
    }

    private function assertVector(VectorInterface $vector): void
    {
        $values = $vector->getData();
        if (\count($values) !== $this->settings->dimensions) {
            throw IndexException::sanitized('Embedding endpoint returned unexpected dimensions.');
        }

        $norm = 0.0;
        foreach ($values as $value) {
            if (!\is_float($value) && !\is_int($value)) {
                throw IndexException::sanitized('Embedding endpoint returned a non-numeric component.');
            }
            $float = (float) $value;
            if (!is_finite($float)) {
                throw IndexException::sanitized('Embedding endpoint returned a non-finite vector.');
            }
            $norm += $float * $float;
        }
        if (0.0 === $norm || !is_finite($norm)) {
            throw IndexException::sanitized('Embedding endpoint returned an invalid vector.');
        }
    }

    private function expectedCount(string|\Stringable|EmbeddableDocumentInterface|array $values): int
    {
        if (\is_array($values)) {
            return \count($values);
        }

        return 1;
    }

    private function embeddingsPath(string $baseUrl): string
    {
        $normalized = rtrim($baseUrl, '/');
        if (str_ends_with($normalized, '/v1')) {
            return '/embeddings';
        }

        return '/v1/embeddings';
    }
}
