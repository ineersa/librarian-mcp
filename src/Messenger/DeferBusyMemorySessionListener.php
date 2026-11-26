<?php

declare(strict_types=1);

namespace App\Messenger;

use App\ObservationalMemory\MemorySessionWorkOutcome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Re-queues a session-busy OM job on the transport it came from, without a retry stamp.
 */
#[AsEventListener]
final readonly class DeferBusyMemorySessionListener
{
    private const string SENDER_PREFIX = 'messenger.transport.';

    public function __construct(
        #[Autowire(service: self::SENDER_PREFIX.MessengerTransport::Om->value)]
        private SenderInterface $omSender,
        #[Autowire(service: self::SENDER_PREFIX.MessengerTransport::OmReflect->value)]
        private SenderInterface $omReflectSender,
    ) {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        if (MemorySessionWorkOutcome::SessionBusy !== $envelope->last(HandledStamp::class)?->getResult()) {
            return;
        }
        $sender = match (MessengerTransport::tryFrom($event->getReceiverName())) {
            MessengerTransport::Om => $this->omSender,
            MessengerTransport::OmReflect => $this->omReflectSender,
            default => null,
        };
        if (null === $sender) {
            return;
        }

        // Messenger acknowledges the original only after this event returns. Let a
        // send failure escape so the original stays unacknowledged at the transport.
        $sender->send($envelope
            ->withoutAll(HandledStamp::class)
            ->withoutAll(TransportMessageIdStamp::class)
            ->withoutAll(DelayStamp::class)
            ->with(new DelayStamp(random_int(60_000, 120_000))));
    }
}
