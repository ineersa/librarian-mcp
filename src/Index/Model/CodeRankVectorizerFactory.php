<?php

declare(strict_types=1);

namespace App\Index\Model;

use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the native Generic CodeRank platform and Document Vectorizer.
 *
 * The endpoint is resolved from Settings when created; EndpointVectorizer recreates it after a reset.
 */
final class CodeRankVectorizerFactory
{
    public function __construct(
        private readonly ModelEndpoints $endpoints,
        private readonly EmbeddingResultValidator $validator,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function createPlatform(ModelEndpoint $endpoint): PlatformInterface
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->validator);

        return GenericFactory::createPlatform(
            baseUrl: $endpoint->url,
            apiKey: $endpoint->apiKey,
            httpClient: $this->httpClient ?? HttpClient::create([
                'timeout' => 30.0,
                'max_duration' => 30.0,
                'extra' => [
                    'trace_content' => false,
                ],
            ]),
            modelCatalog: new ModelCatalog([
                $endpoint->model => [
                    'class' => EmbeddingsModel::class,
                    'capabilities' => [
                        Capability::INPUT_TEXT,
                        Capability::INPUT_MULTIPLE,
                        Capability::EMBEDDINGS,
                    ],
                ],
            ]),
            eventDispatcher: $dispatcher,
            supportsCompletions: false,
            supportsEmbeddings: true,
            embeddingsPath: self::embeddingsPath($endpoint->url),
            name: EmbeddingResultValidator::PROVIDER_NAME,
        );
    }

    /** Vectorizer for the given endpoint, or for the one currently selected in Settings. */
    public function create(?ModelEndpoint $endpoint = null): Vectorizer
    {
        $endpoint ??= $this->endpoints->embedding();

        return new Vectorizer(
            $this->createPlatform($endpoint),
            $endpoint->model,
            includeText: true,
        );
    }

    private static function embeddingsPath(string $baseUrl): string
    {
        $normalized = rtrim($baseUrl, '/');
        if (str_ends_with($normalized, '/v1')) {
            return '/embeddings';
        }

        return '/v1/embeddings';
    }
}
