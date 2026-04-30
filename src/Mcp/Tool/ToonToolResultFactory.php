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
        $encoded = Toon::encode($value);

        // TOON encodes an empty list to an empty string. Return an explicit marker
        // so clients can distinguish "no results" from transport/output glitches.
        if ([] === $value && '' === $encoded) {
            $encoded = '[]';
        }

        return CallToolResult::success([
            new TextContent($encoded),
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
