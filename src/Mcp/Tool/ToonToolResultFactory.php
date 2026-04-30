<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use HelgeSverre\Toon\Toon;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class ToonToolResultFactory
{
    public function success(mixed $value): CallToolResult
    {
        return CallToolResult::success([
            new TextContent(Toon::encode($value)),
        ]);
    }

    public function error(string $message, bool $retryable, string $hint): CallToolResult
    {
        return CallToolResult::error([
            new TextContent(Toon::encode([
                'message' => $message,
                'retryable' => $retryable,
                'hint' => $hint,
            ])),
        ]);
    }
}
