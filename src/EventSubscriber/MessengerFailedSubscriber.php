<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Library;
use App\Mcp\LibraryMetadataCorpus;
use App\Message\SyncLibraryMessage;
use App\Repository\LibraryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Catches messenger failures that escape the handler's own try/catch
 * (e.g. container construction errors) and transitions the library to Failed
 * with a Mercure status publish.
 */
class MessengerFailedSubscriber implements EventSubscriberInterface
{
    private const MERCURE_TOPIC = 'https://librarian-mcp.local/topics/libraries';

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function __construct(
        private readonly LibraryRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $mercureHub,
        private readonly LibraryMetadataCorpus $metadataCorpus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof SyncLibraryMessage) {
            return;
        }

        $library = $this->repository->find($message->libraryId);
        if (null === $library) {
            return;
        }

        // Only act if still in Queued or Indexing — the handler's own catch
        // already transitions Indexing → Failed, so this mainly covers
        // pre-invoke errors where the library is stuck in Queued.
        if (!$library->getStatus()->isQueued() && !$library->getStatus()->isIndexing()) {
            return;
        }

        $error = $event->getThrowable()->getMessage();

        $library->syncFailed($error);
        $this->em->flush();
        $this->metadataCorpus->upsert($library);

        $this->publishStatus($library, $error);
    }

    private function publishStatus(Library $library, string $error): void
    {
        $payload = [
            'libraryId' => $library->getId(),
            'status' => $library->getStatus()->value,
            'lastError' => mb_substr($error, 0, 2000),
        ];

        $json = json_encode($payload, \JSON_THROW_ON_ERROR);

        $this->logger->info('Publishing Mercure library failure status (from subscriber).', [
            'topic' => self::MERCURE_TOPIC,
            'payload' => $payload,
        ]);

        $this->mercureHub->publish(new Update(self::MERCURE_TOPIC, $json));
    }
}
