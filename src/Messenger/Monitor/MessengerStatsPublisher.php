<?php

declare(strict_types=1);

namespace App\Messenger\Monitor;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class MessengerStatsPublisher
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private MessengerStatsProvider $stats,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(): void
    {
        try {
            $payload = $this->twig->render('admin/messenger/update.stream.html.twig', ['stats' => $this->stats->snapshot()]);
            $this->hub->publish(new Update([MessengerStatsTopics::DASHBOARD], $payload, private: true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Messenger dashboard update could not be published.', ['error' => $exception->getMessage()]);
        }
    }
}
