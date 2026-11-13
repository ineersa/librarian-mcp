<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexSettings;
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
 * This is DI glue only: no VectorizerInterface replacement wrapper.
 */
final class CodeRankVectorizerFactory
{
    public function __construct(
        private readonly IndexSettings $settings,
        private readonly EmbeddingResultValidator $validator,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function createPlatform(): PlatformInterface
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->validator);

        return GenericFactory::createPlatform(
            baseUrl: $this->settings->embeddingUrl,
            httpClient: $this->httpClient ?? HttpClient::create([
                'timeout' => 30.0,
                'max_duration' => 30.0,
                'extra' => [
                    'trace_content' => false,
                ],
            ]),
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
            eventDispatcher: $dispatcher,
            supportsCompletions: false,
            supportsEmbeddings: true,
            embeddingsPath: self::embeddingsPath($this->settings->embeddingUrl),
            name: EmbeddingResultValidator::PROVIDER_NAME,
        );
    }

    public function create(): Vectorizer
    {
        return new Vectorizer(
            $this->createPlatform(),
            $this->settings->embeddingModel,
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
