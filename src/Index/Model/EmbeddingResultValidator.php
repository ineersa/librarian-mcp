<?php

declare(strict_types=1);

namespace App\Index\Model;

use App\Index\IndexException;
use App\Index\IndexSettings;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates CodeRank embedding results after native platform conversion.
 *
 * Bound only to the librarian-coderank Generic platform dispatcher so other
 * model traffic is unaffected. Listener exceptions propagate to the caller.
 */
final class EmbeddingResultValidator implements EventSubscriberInterface
{
    public const string PROVIDER_NAME = 'librarian-coderank';

    public function __construct(
        private readonly IndexSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ResultConvertedEvent::class => 'onResultConverted',
        ];
    }

    public function onResultConverted(ResultConvertedEvent $event): void
    {
        if (self::PROVIDER_NAME !== $event->getProvider()?->getName()) {
            return;
        }

        $result = $event->getResult();
        if (!$result instanceof VectorResult) {
            return;
        }

        /** @var list<Vector> $vectors */
        $vectors = $result->getContent();
        $expected = $this->expectedCount($event->getInput());
        if (\count($vectors) !== $expected) {
            throw IndexException::sanitized('Embedding endpoint returned an invalid vector count.');
        }

        foreach ($vectors as $vector) {
            $this->assertVector($vector);
        }
    }

    /** @param array<string, mixed>|string|object $input */
    private function expectedCount(array|string|object $input): int
    {
        return \is_array($input) ? \count($input) : 1;
    }

    private function assertVector(Vector $vector): void
    {
        $values = $vector->getData();
        if (\count($values) !== $this->settings->dimensions) {
            throw IndexException::sanitized('Embedding endpoint returned unexpected dimensions.');
        }

        $norm = 0.0;
        foreach ($values as $value) {
            if (!\is_float($value) && !\is_int($value)) {
                throw IndexException::sanitized('Embedding endpoint returned a non-numeric component.');
            }
            $float = (float) $value;
            if (!is_finite($float)) {
                throw IndexException::sanitized('Embedding endpoint returned a non-finite vector.');
            }
            $norm += $float * $float;
        }

        if (0.0 === $norm || !is_finite($norm)) {
            throw IndexException::sanitized('Embedding endpoint returned an invalid vector.');
        }
    }
}
