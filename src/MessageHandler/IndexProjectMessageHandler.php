<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Index\ProjectIndexer;
use App\Index\ProjectIndexManager;
use App\Message\IndexProjectMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexProjectMessageHandler
{
    public function __construct(
        private readonly ProjectIndexer $projectIndexer,
        private readonly ProjectIndexManager $projectIndexManager,
    ) {
    }

    public function __invoke(IndexProjectMessage $message): void
    {
        $needsContinuation = $this->projectIndexer->process($message->projectId);
        if ($needsContinuation) {
            // Queue-only dedupe already released on receive, so a bounded
            // continuation can be admitted immediately.
            $this->projectIndexManager->queueId($message->projectId);
        }
    }
}
