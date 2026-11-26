<?php

declare(strict_types=1);

namespace App\Index\Model;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\DocumentInterface;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Delegates to the native Vectorizer for the endpoint selected in Settings.
 *
 * The kernel resets services between FrankenPHP worker requests and Messenger messages, so a saved
 * endpoint change applies to the next request or message; one indexing run keeps one endpoint.
 */
final class EndpointVectorizer implements VectorizerInterface, ResetInterface
{
    private ?Vectorizer $vectorizer = null;

    public function __construct(
        private readonly CodeRankVectorizerFactory $factory,
    ) {
    }

    public function vectorize(string|\Stringable|DocumentInterface|array $values, array $options = []): Vector|VectorDocumentInterface|array
    {
        $this->vectorizer ??= $this->factory->create();

        return $this->vectorizer->vectorize($values, $options);
    }

    public function reset(): void
    {
        $this->vectorizer = null;
    }
}
