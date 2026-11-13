<?php

declare(strict_types=1);

namespace App\Index\Model;

use Symfony\AI\Platform\Vector\Vector;

/**
 * Public embedding entry point for search and capability checks.
 */
final readonly class ModelGateway
{
    public function __construct(
        private ValidatingVectorizer $vectorizer,
    ) {
    }

    public function embedQuery(string $question): Vector
    {
        return $this->vectorizer->embedQuery($question);
    }
}
