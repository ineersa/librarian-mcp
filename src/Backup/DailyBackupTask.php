<?php

declare(strict_types=1);

namespace App\Backup;

use App\Cron\CronJobFailedException;
use App\Cron\CronJobTask;
use App\Entity\CronJob;
use App\Entity\Project;
use App\Memory\ClaudeTranscriptReader;
use App\Project\HatfieldSourceDiscovery;
use App\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Daily backup: Librarian Postgres, and per registered project its `.hatfield` data and
 * Claude Code transcripts. Each archive is uploaded under its own prefix and that prefix
 * keeps its newest three objects. One success email lists every upload.
 *
 * Per-project prefixes start with the project's stable ID (`<id>-<slug>`), so different names
 * with the same slug never share objects or retention. A registered root that is missing or
 * unreadable fails the run instead of being treated as a project without data.
 *
 * A failing source does not stop the others; the run then fails without an email and the
 * next schedule tick retries.
 */
final readonly class DailyBackupTask implements CronJobTask
{
    public const string PREFIX_POSTGRES = 'librarian/postgres/';
    public const string PREFIX_HATFIELD = 'hatfield/';
    public const string PREFIX_CLAUDE = 'claude/';
    private const string STAMP_FORMAT = 'Y_m_d_H_i_s';

    public function __construct(
        private ProjectRepository $projectRepository,
        private PostgresDumper $postgresDumper,
        private HatfieldArchiver $hatfieldArchiver,
        private HatfieldSourceDiscovery $sourceDiscovery,
        private ClaudeTranscriptReader $claudeTranscripts,
        private BackupStorage $storage,
        private BackupRetention $retention,
        private BackupNotifier $notifier,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/%env(BACKUP_WORK_DIR)%')]
        private string $workDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function job(): CronJob
    {
        return CronJob::Backup;
    }

    public function run(\Closure $heartbeat): array
    {
        $this->filesystem->mkdir($this->workDir);
        $stamp = $this->clock->now()->format(self::STAMP_FORMAT);
        $uploaded = [];
        $errors = [];

        $this->backup('Postgres', self::PREFIX_POSTGRES, 'postgres_'.$stamp.'.dump',
            fn (string $file): bool => $this->dumpPostgres($file), $uploaded, $errors);
        $heartbeat();

        $slugger = new AsciiSlugger();
        foreach ($this->projectRepository->findBy([], ['id' => 'ASC']) as $project) {
            $root = $this->projectRoot($project);
            if (!is_dir($root) || !is_readable($root)) {
                $this->logger->error('Backup of project {project} failed: root {root} is unavailable.', ['project' => $project->name, 'root' => $root]);
                $errors[] = \sprintf('Project %s: root %s is missing or unreadable.', $project->name, $root);
                continue;
            }

            $slug = $slugger->slug($project->name)->lower()->toString();
            $key = '' !== $slug ? $project->id.'-'.$slug : (string) $project->id;

            $this->backup('Hatfield '.$project->name, self::PREFIX_HATFIELD.$key.'/', 'hatfield_'.$key.'_'.$stamp.'.tar.gz',
                fn (string $file): bool => $this->hatfieldArchiver->archive($root, $this->sourceDiscovery->resolveSessionsDirectory($root), $file, $this->workDir), $uploaded, $errors);
            $heartbeat();

            $this->backup('Claude '.$project->name, self::PREFIX_CLAUDE.$key.'/', 'claude_'.$key.'_'.$stamp.'.tar.gz',
                fn (string $file): bool => $this->archiveClaude($root, $file), $uploaded, $errors);
            $heartbeat();
        }

        if ([] !== $errors) {
            throw new CronJobFailedException(implode("\n", $errors), $uploaded);
        }

        $this->notifier->sendSuccess($uploaded);

        return $uploaded;
    }

    /**
     * @param \Closure(string): bool $build    writes the archive; false when the source does not exist
     * @param list<string>           $uploaded
     * @param list<string>           $errors
     */
    private function backup(string $label, string $prefix, string $fileName, \Closure $build, array &$uploaded, array &$errors): void
    {
        $file = $this->workDir.'/'.$fileName;
        try {
            if (!$build($file)) {
                return;
            }
            $object = $prefix.$fileName;
            $this->storage->upload($file, $object);
            $uploaded[] = $this->storage->uri($object);
            $this->retention->apply($prefix);
        } catch (\Throwable $exception) {
            $this->logger->error('Backup of {source} failed.', ['source' => $label, 'exception' => $exception]);
            $errors[] = $label.': '.$exception->getMessage();
        } finally {
            $this->filesystem->remove($file);
        }
    }

    private function dumpPostgres(string $file): bool
    {
        $this->postgresDumper->dump($file);

        return true;
    }

    private function archiveClaude(string $projectRoot, string $file): bool
    {
        $directory = $this->claudeTranscripts->transcriptDirectory($projectRoot);
        if (null === $directory) {
            return false;
        }

        ArchiveCommand::run(
            ['tar', '-czf', $file, '-C', \dirname($directory), '--warning=no-file-changed', '--warning=no-file-removed', '--', basename($directory)],
            acceptedExitCodes: [0, ArchiveCommand::TAR_FILES_CHANGED],
        );

        return true;
    }

    private function projectRoot(Project $project): string
    {
        return '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
    }
}
