<?php

declare(strict_types=1);

namespace App\Index\Model;

/**
 * One resolved model endpoint. The API key is never part of string output.
 */
final readonly class ModelEndpoint
{
    public function __construct(
        public string $url,
        public string $model,
        #[\SensitiveParameter]
        public ?string $apiKey = null,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'model' => $this->model, 'apiKey' => null === $this->apiKey ? null : '***'];
    }

    /** Endpoint base without a trailing `/v1`, for clients that append versioned paths themselves. */
    public function baseUrl(): string
    {
        $normalized = rtrim($this->url, '/');

        return str_ends_with($normalized, '/v1') ? substr($normalized, 0, -3) : $normalized;
    }

    /** True when requests go to the same server and model; the key does not change the model. */
    public function sameTarget(self $other): bool
    {
        return $this->baseUrl() === $other->baseUrl() && $this->model === $other->model;
    }
}
