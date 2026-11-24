<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class OpenCodeDeepseekModelClient extends ModelClient
{
    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        // DeepSeek requires the field on replay, including tool turns with no reasoning delta.
        if (\is_array($payload) && isset($payload['messages']) && \is_array($payload['messages'])) {
            foreach ($payload['messages'] as &$message) {
                if (\is_array($message) && Role::Assistant->value === ($message['role'] ?? null)) {
                    $message['reasoning_content'] ??= '';
                }
            }
            unset($message);
        }

        return parent::request($model, $payload, $options);
    }
}
