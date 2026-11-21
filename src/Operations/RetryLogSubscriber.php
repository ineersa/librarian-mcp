<?php

declare(strict_types=1);

namespace App\Operations;

use App\Messenger\MessengerTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

#[AsEventListener(event: WorkerMessageRetriedEvent::class)]
final readonly class RetryLogSubscriber
{
    public const string CHANNEL = 'operations_retry';

    public function __construct(
        #[Autowire(service: 'monolog.logger.'.self::CHANNEL)]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageRetriedEvent $event): void
    {
        $transport = MessengerTransport::tryFrom($event->getReceiverName());
        if (!\in_array($transport, MessengerTransport::monitored(), true)) {
            return;
        }

        $envelope = $event->getEnvelope();
        $retry = $envelope->last(RedeliveryStamp::class);
        $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;
        if (null === $retry || $delay <= 0) {
            return;
        }

        // Doctrine stores available_at at second precision and rounds positive delays up.
        $scheduled = (int) ceil((float) $retry->getRedeliveredAt()->format('U.u') + $delay / 1000);
        $this->logger->warning('Messenger scheduled a delayed retry.', [
            'transport' => $transport->value,
            'job' => $envelope->getMessage()::class,
            'attempt' => $retry->getRetryCount(),
            'nextAt' => $scheduled,
            'exceptionClass' => $envelope->last(ErrorDetailsStamp::class)?->getExceptionClass(),
        ]);
    }
}
