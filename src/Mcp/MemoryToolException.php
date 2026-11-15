<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Memory\MemoryErrorCode;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class MemoryToolException extends \RuntimeException
{
    public function __construct(public readonly MemoryErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
