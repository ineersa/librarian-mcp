<?php

declare(strict_types=1);

namespace App\Command;

use App\Index\ProjectIndexer;
use App\Index\ProjectIndexManager;
use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:index-project',
    description: 'Queue or synchronously process bounded indexing for one project',
)]
final class IndexProjectCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly ProjectIndexer $projectIndexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Registered project ID')
            ->addOption('drain', null, InputOption::VALUE_NONE, 'Process bounded units synchronously until finished')
            ->addOption('queue-only', null, InputOption::VALUE_NONE, 'Only enqueue Messenger work (default without --drain)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectId = (int) $input->getArgument('projectId');
        if ($projectId < 1) {
            $io->error('Project id must be a positive integer.');

            return Command::FAILURE;
        }

        $project = $this->projectRepository->find($projectId);
        if (null === $project) {
            $io->error(\sprintf('Project %d was not found.', $projectId));

            return Command::FAILURE;
        }

        if (true === $input->getOption('drain')) {
            $rounds = 0;
            do {
                $continue = $this->projectIndexer->process($projectId);
                ++$rounds;
            } while ($continue);

            $io->success(\sprintf('Drained project %d indexing in %d bounded unit(s).', $projectId, $rounds));

            return Command::SUCCESS;
        }

        $this->projectIndexManager->queueId($projectId);
        $io->success(\sprintf('Queued indexing for project %d.', $projectId));

        return Command::SUCCESS;
    }
}
