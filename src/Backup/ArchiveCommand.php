<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\Process\Process;

/**
 * Runs archiving tools (tar, gzip, pg_dump) without a shell.
 */
final class ArchiveCommand
{
    /** GNU tar exit code 1: some files changed while being read (live append-only journals). */
    public const int TAR_FILES_CHANGED = 1;
    private const float TIMEOUT_SECONDS = 3600.0;

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param list<int>             $acceptedExitCodes
     */
    public static function run(array $command, array $env = [], array $acceptedExitCodes = [0]): void
    {
        $process = new Process($command, null, $env, null, self::TIMEOUT_SECONDS);
        $process->run();
        if (!\in_array($process->getExitCode(), $acceptedExitCodes, true)) {
            throw new \RuntimeException(\sprintf('%s exited with %s: %s', $command[0], (string) $process->getExitCode(), mb_substr(trim($process->getErrorOutput()), 0, 500)));
        }
    }
}
