<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * One success email per backup run, like the cron-jobs EmailService. Failures send nothing.
 */
final readonly class BackupNotifier
{
    private const string SUBJECT = 'Librarian backup successful';

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'MAILER_TO')]
        private string $to,
    ) {
    }

    /**
     * @param list<string> $uploaded object URIs
     */
    public function sendSuccess(array $uploaded): void
    {
        if ('' === trim($this->from) || '' === trim($this->to)) {
            $this->logger->notice('Backup succeeded; email skipped because MAILER_FROM or MAILER_TO is empty.');

            return;
        }

        $items = implode('', array_map(
            static fn (string $uri): string => '<li>'.htmlspecialchars($uri, \ENT_QUOTES | \ENT_SUBSTITUTE).'</li>',
            $uploaded,
        ));

        $this->mailer->send((new Email())
            ->from($this->from)
            ->to($this->to)
            ->subject(self::SUBJECT)
            ->html(\sprintf('Backup of Librarian was successful. Files created:<ul>%s</ul>', $items)));
    }
}
