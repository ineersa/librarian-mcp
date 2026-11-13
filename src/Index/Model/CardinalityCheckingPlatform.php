<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/** Validate before the vendor document vectorizer can discard surplus vectors. */
final readonly class CardinalityCheckingPlatform implements PlatformInterface
{
    public function __construct(private PlatformInterface $inner)
    {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $result = $this->inner->invoke($model, $input, $options);
        $expected = \is_array($input) ? \count($input) : 1;
        if (\count($result->asVectors()) !== $expected) {
            throw IndexException::sanitized('Embedding endpoint returned an invalid vector count.');
        }

        return $result;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
