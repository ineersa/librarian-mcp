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

#[AsEventListener]
final readonly class DeferBusyMemorySessionListener
{
    private const string TRANSPORT = 'om';

    public function __construct(
        #[Autowire(service: 'messenger.transport.'.self::TRANSPORT)]
        private SenderInterface $sender,
    ) {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        if (self::TRANSPORT !== $event->getReceiverName() || MemorySessionWorkOutcome::SessionBusy !== $envelope->last(HandledStamp::class)?->getResult()) {
            return;
        }

        // Messenger acknowledges the original only after this event returns. Let a
        // send failure escape so the original stays unacknowledged at the transport.
        $this->sender->send($envelope
            ->withoutAll(HandledStamp::class)
            ->withoutAll(TransportMessageIdStamp::class)
            ->withoutAll(DelayStamp::class)
            ->with(new DelayStamp(random_int(60_000, 120_000))));
    }
}
