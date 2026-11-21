<?php

declare(strict_types=1);

namespace App\Messenger\Monitor;

use App\Messenger\MessengerTransport;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Failure\FailedMessageRepository;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final readonly class MessengerStatsProvider
{
    private const string TABLE = 'messenger_messages';
    private const int FAILED_LIMIT = 50;

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $receivers,
        private FailedMessageRepository $failed,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function snapshot(): MessengerStats
    {
        $transports = MessengerTransport::monitored();
        $delayed = $this->connection->fetchAllKeyValue(
            'SELECT queue_name, COUNT(*) FROM '.self::TABLE.' WHERE queue_name IN (:queues) AND delivered_at IS NULL AND available_at > :now GROUP BY queue_name',
            [
                'queues' => array_map(static fn (MessengerTransport $transport): string => $transport->queueName(), $transports),
                'now' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
            ],
            ['queues' => ArrayParameterType::STRING, 'now' => Types::DATETIME_IMMUTABLE],
        );

        $queues = [];
        foreach ($transports as $transport) {
            $receiver = $this->receivers->get($transport->value);
            $count = $delayed[$transport->queueName()] ?? 0;
            $queues[$transport->value] = [
                'queued' => $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null,
                'delayed' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        $total = $this->failed->count();
        $failures = [];
        foreach ($this->failed->all(limit: self::FAILED_LIMIT) as $envelope) {
            $id = FailedMessageRepository::getMessageId($envelope);
            if (!\is_int($id) && !\is_string($id)) {
                continue;
            }

            $message = $envelope->getMessage();
            $error = $envelope->last(ErrorDetailsStamp::class);
            $redelivery = $envelope->all(RedeliveryStamp::class);
            $failure = array_pop($redelivery);
            // Messenger appends a zero-count stamp when moving the job to the failure transport.
            $previous = array_pop($redelivery);
            $lastRetryCount = $failure?->getRetryCount() ?? 0;
            $failures[] = [
                'id' => (string) $id,
                'class' => $message::class,
                'transport' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
                'failedAt' => $failure?->getRedeliveredAt(),
                'retries' => 0 === $lastRetryCount ? ($previous?->getRetryCount() ?? 0) : $lastRetryCount,
                'errorClass' => $error?->getExceptionClass(),
                'error' => null === $error ? null : mb_strimwidth($error->getExceptionMessage(), 0, 500, '…', 'UTF-8'),
            ];
        }
        usort($failures, static function (array $a, array $b): int {
            $byTime = $b['failedAt'] <=> $a['failedAt'];

            return 0 === $byTime ? $b['id'] <=> $a['id'] : $byTime;
        });

        return new MessengerStats($queues, $total, $failures);
    }
}
