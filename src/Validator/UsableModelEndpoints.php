<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A changed embeddings or reranker endpoint must respond, and a changed embeddings endpoint must
 * serve the model the existing indexes were built with.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UsableModelEndpoints extends Constraint
{
    public string $unreachableMessage = 'The {{ model }} endpoint {{ url }} does not respond to GET /v1/models.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
