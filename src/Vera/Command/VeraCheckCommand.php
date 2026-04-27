<?php

declare(strict_types=1);

namespace App\Vera\Command;

use App\Vera\VeraCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:vera:check',
    description: 'Verify vera CLI is available and configured',
)]
class VeraCheckCommand extends Command
{
    public function __construct(
        private readonly VeraCli $veraCli,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->veraCli->isAvailable()) {
            $version = $this->veraCli->getVersion();
            $io->success(\sprintf('Vera CLI is available: %s', $version));
        } else {
            $io->error('Vera CLI is NOT available. Check VERA_BINARY env var.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
