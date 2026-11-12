<?php

declare(strict_types=1);

namespace App\Project;

final class ProjectSourceException extends \RuntimeException
{
    public function __construct(
        public readonly ProjectSourceIssueCode $issueCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
