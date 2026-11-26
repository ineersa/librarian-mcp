<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Entity\LibrarianSettings;
use App\Repository\LibrarianSettingsRepository;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the embeddings and reranker endpoints from Settings, falling back to the env defaults.
 *
 * Read per operation so a saved change applies to the next request or Messenger message without a
 * container restart. API keys come only from fixed env variables and are never sent to built-in services.
 */
final class ModelEndpoints implements ResetInterface
{
    /** Compose service names and `--alias` values of the optional `models` profile. */
    public const string BUILT_IN_EMBEDDING_URL = 'http://embeddings:8080/v1';
    public const string BUILT_IN_EMBEDDING_MODEL = 'CodeRankEmbed';
    public const string BUILT_IN_RERANKER_URL = 'http://reranker:8080/v1';
    public const string BUILT_IN_RERANKER_MODEL = 'bge-reranker-base';
    public const string ENV_EMBEDDING_API_KEY = 'EMBEDDING_API_KEY';
    public const string ENV_RERANKER_API_KEY = 'RERANKER_API_KEY';
    private const float PROBE_TIMEOUT = 1.5;
    private const string MODELS_PATH = '/v1/models';

    private readonly HttpClientInterface $httpClient;
    /** @var array<string, bool> */
    private array $probes = [];

    public function __construct(
        private readonly LibrarianSettingsRepository $settings,
        public readonly string $defaultEmbeddingUrl,
        public readonly string $defaultEmbeddingModel,
        public readonly string $defaultRerankerUrl,
        public readonly string $defaultRerankerModel,
        #[\SensitiveParameter]
        private readonly string $embeddingApiKey = '',
        #[\SensitiveParameter]
        private readonly string $rerankerApiKey = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['extra' => ['trace_content' => false]]);
    }

    public function embedding(): ModelEndpoint
    {
        return $this->embeddingFor($this->settings->current());
    }

    public function reranker(): ModelEndpoint
    {
        return $this->rerankerFor($this->settings->current());
    }

    public function embeddingFor(?LibrarianSettings $settings): ModelEndpoint
    {
        return $this->resolveEmbedding(
            $settings->embeddingMode ?? ModelEndpointMode::Api,
            $settings?->embeddingApiUrl,
            $settings?->embeddingApiModel,
        );
    }

    public function rerankerFor(?LibrarianSettings $settings): ModelEndpoint
    {
        return $this->resolveReranker(
            $settings->rerankerMode ?? ModelEndpointMode::Api,
            $settings?->rerankerApiUrl,
            $settings?->rerankerApiModel,
        );
    }

    public function resolveEmbedding(ModelEndpointMode $mode, ?string $apiUrl, ?string $apiModel): ModelEndpoint
    {
        return match ($mode) {
            ModelEndpointMode::BuiltIn => new ModelEndpoint(self::BUILT_IN_EMBEDDING_URL, self::BUILT_IN_EMBEDDING_MODEL),
            ModelEndpointMode::Api => new ModelEndpoint(
                self::orDefault($apiUrl, $this->defaultEmbeddingUrl),
                self::orDefault($apiModel, $this->defaultEmbeddingModel),
                self::orNull($this->embeddingApiKey),
            ),
        };
    }

    public function resolveReranker(ModelEndpointMode $mode, ?string $apiUrl, ?string $apiModel): ModelEndpoint
    {
        return match ($mode) {
            ModelEndpointMode::BuiltIn => new ModelEndpoint(self::BUILT_IN_RERANKER_URL, self::BUILT_IN_RERANKER_MODEL),
            ModelEndpointMode::Api => new ModelEndpoint(
                self::orDefault($apiUrl, $this->defaultRerankerUrl),
                self::orDefault($apiModel, $this->defaultRerankerModel),
                self::orNull($this->rerankerApiKey),
            ),
        };
    }

    public function hasEmbeddingApiKey(): bool
    {
        return null !== self::orNull($this->embeddingApiKey);
    }

    public function hasRerankerApiKey(): bool
    {
        return null !== self::orNull($this->rerankerApiKey);
    }

    /** OpenAI-compatible `GET /v1/models` answers 2xx within a short timeout. Memoized until reset. */
    public function responds(ModelEndpoint $endpoint): bool
    {
        $url = $endpoint->baseUrl().self::MODELS_PATH;
        $key = $url."\0".($endpoint->apiKey ?? '');
        if (isset($this->probes[$key])) {
            return $this->probes[$key];
        }

        try {
            $status = $this->httpClient->request('GET', $url, [
                'timeout' => self::PROBE_TIMEOUT,
                'max_duration' => self::PROBE_TIMEOUT,
                'auth_bearer' => $endpoint->apiKey,
            ])->getStatusCode();
            $up = $status >= 200 && $status < 300;
        } catch (\Throwable) {
            $up = false;
        }

        return $this->probes[$key] = $up;
    }

    public function reset(): void
    {
        $this->probes = [];
    }

    private static function orDefault(?string $value, string $default): string
    {
        return self::orNull($value) ?? $default;
    }

    private static function orNull(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }
}
