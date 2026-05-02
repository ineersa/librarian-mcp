<?php

declare(strict_types=1);

namespace App\Vera;

/**
 * Structured exception for vera CLI failures.
 */
class VeraCliException extends \RuntimeException
{
    /**
     * @param array<int, string> $command
     */
    public static function commandFailed(array $command, int $exitCode, string $errorOutput): self
    {
        $binary = $command[0] ?? 'unknown';
        $trimmedErrorOutput = trim($errorOutput);

        return new self(
            \sprintf(
                '%s failed (exit %d): %s',
                basename($binary),
                $exitCode,
                '' !== $trimmedErrorOutput ? $trimmedErrorOutput : 'no output',
            ),
            $exitCode,
        );
    }

    /**
     * @param array<int, string> $command
     */
    public static function processError(array $command, string $reason): self
    {
        $binary = $command[0] ?? 'unknown';

        return new self(\sprintf('%s process error: %s', basename($binary), $reason));
    }

    public static function notCloned(string $path): self
    {
        return new self(\sprintf('Repository at "%s" has not been cloned yet.', $path));
    }
}
