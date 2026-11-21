<?php

declare(strict_types=1);

namespace App\Command;

use App\Index\ProjectIndexManager;
use App\Index\Status\ProjectStatusPublisher;
use App\ObservationalMemory\Import\OmSqliteImporter;
use App\Project\ProjectSourceException;
use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:om:import',
    description: 'Copy a project\'s Hatfield OM SQLite memory into Librarian (explicit one-time, read-only source)',
)]
final class ImportObservationalMemoryCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly OmSqliteImporter $importer,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly ProjectStatusPublisher $projectStatusPublisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Registered project ID')
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Import only this OM run/session id')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Absolute or project-relative legacy OM SQLite path (defaults to settings/default path)');
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

        $session = $input->getOption('session');
        $database = $input->getOption('database');

        try {
            $totals = $this->importer->import(
                $project,
                \is_string($session) && '' !== $session ? $session : null,
                \is_string($database) && '' !== $database ? $database : null,
            );
        } catch (ProjectSourceException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($totals as $table => $count) {
            $rows[] = [$table, $count['source'], $count['inserted']];
        }
        $io->table(['Table', 'Source rows', 'Inserted'], $rows);

        $this->projectIndexManager->queueId($projectId);
        $this->projectStatusPublisher->publish($projectId);
        $io->success(\sprintf('Imported OM memory for project %s and queued indexing.', $project->name));

        return Command::SUCCESS;
    }
}
