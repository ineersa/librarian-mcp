<?php

declare(strict_types=1);

namespace App\Index\Model;

/**
 * Where an index model (embeddings or reranker) is served from.
 */
enum ModelEndpointMode: string
{
    /** The optional `models` Compose profile services inside the stack. */
    case BuiltIn = 'built_in';
    /** An external OpenAI-compatible endpoint, such as host llama.cpp. */
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::BuiltIn => 'Built-in',
            self::Api => 'API',
        };
    }
}
