<?php

declare(strict_types=1);

namespace App\Messenger\Monitor;

use App\Controller\Admin\DashboardController;
use App\Messenger\MessengerTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;

final readonly class MessengerStatsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessengerStatsPublisher $publisher,
        private RequestStack $requests,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageSentToTransportsEvent::class => 'onSent',
            WorkerMessageReceivedEvent::class => 'onWorkerMessage',
            WorkerMessageHandledEvent::class => 'onWorkerMessage',
            // Run after Messenger's retry and failure-transport listeners.
            WorkerMessageFailedEvent::class => ['onFailure', -128],
            WorkerMessageSkipEvent::class => ['onFailure', -128],
            WorkerMessageRetriedEvent::class => 'onWorkerMessage',
        ];
    }

    public function onSent(MessageSentToTransportsEvent $event): void
    {
        // The admin publishes once after the failed rows have been acknowledged.
        if (\in_array($this->requests->getCurrentRequest()?->attributes->get('_route'), DashboardController::MESSENGER_RETRY_ROUTES, true)) {
            return;
        }

        foreach (MessengerTransport::monitored() as $transport) {
            if (isset($event->getSenders()[$transport->value])) {
                $this->publisher->publish();

                return;
            }
        }
    }

    public function onWorkerMessage(WorkerMessageReceivedEvent|WorkerMessageHandledEvent|WorkerMessageRetriedEvent $event): void
    {
        if (\in_array(MessengerTransport::tryFrom($event->getReceiverName()), MessengerTransport::monitored(), true)) {
            $this->publisher->publish();
        }
    }

    public function onFailure(WorkerMessageFailedEvent|WorkerMessageSkipEvent $event): void
    {
        if ($event instanceof WorkerMessageFailedEvent && $event->willRetry()) {
            return; // WorkerMessageRetriedEvent already refreshed the queue cards.
        }

        $transport = MessengerTransport::tryFrom($event->getReceiverName());
        if (null !== $transport) {
            $this->publisher->publish(includeFailures: true);
        }
    }
}
