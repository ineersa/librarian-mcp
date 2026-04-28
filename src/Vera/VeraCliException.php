<?php

declare(strict_types=1);

namespace App\Vera;

/**
 * Structured exception for vera CLI failures.
 */
class VeraCliException extends \RuntimeException
{
    private ?string $command = null;
    private ?int $exitCode = null;

    public static function commandFailed(array $command, int $exitCode, string $errorOutput): self
    {
        $binary = $command[0] ?? 'unknown';
        $message = \sprintf(
            '%s failed (exit %d): %s',
            basename($binary),
            $exitCode,
            trim($errorOutput) ?: 'no output',
        );

        return (new self($message, $exitCode))
            ->withCommand($command)
            ->withExitCode($exitCode);
    }

    public static function processError(array $command, string $reason): self
    {
        $binary = $command[0] ?? 'unknown';

        return (new self(\sprintf('%s process error: %s', basename($binary), $reason)))
            ->withCommand($command);
    }

    public static function notCloned(string $path): self
    {
        return new self(\sprintf('Repository at "%s" has not been cloned yet.', $path));
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function getProcessExitCode(): ?int
    {
        return $this->exitCode;
    }

    private function withCommand(array $command): self
    {
        $this->command = implode(' ', array_map(static fn (string $a) => escapeshellarg($a), $command));

        return $this;
    }

    private function withExitCode(int $exitCode): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }
}
