<?php

declare(strict_types=1);

namespace App\Command;

use App\ObservationalMemory\Import\OmSqliteImporter;
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
    description: 'Copy a project\'s Hatfield OM SQLite memory into Librarian (read-only source, idempotent)',
)]
final class ImportObservationalMemoryCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly OmSqliteImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projectId', InputArgument::REQUIRED, 'Registered project ID')
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Import only this OM run/session id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = $this->projectRepository->find((int) $input->getArgument('projectId'));
        if (null === $project) {
            $io->error('Project was not found.');

            return Command::FAILURE;
        }

        $session = $input->getOption('session');
        $totals = $this->importer->import($project, \is_string($session) ? $session : null);

        $rows = [];
        foreach ($totals as $table => $count) {
            $rows[] = [$table, $count['source'], $count['inserted']];
        }
        $io->table(['Table', 'Source rows', 'Inserted or updated'], $rows);
        $io->success(\sprintf('Imported OM memory for project %s.', $project->name));

        return Command::SUCCESS;
    }
}
