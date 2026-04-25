<?php

declare(strict_types=1);

namespace mate;

use Castor\Attribute\AsTask;
use function CastorTasks\dev_php_exec;
use function CastorTasks\mate_tools_validate_format;
use function CastorTasks\mate_tools_validate_tool_name;

#[AsTask(name: 'tools:list', namespace: 'mate-tools', description: 'List Mate MCP tools (mcp:tools:list); formats: table, json, toon')]
function tooling_list(string $format = 'toon'): void
{
    $format = mate_tools_validate_format($format, ['table', 'json', 'toon']);
    dev_php_exec('php vendor/bin/mate mcp:tools:list --format='.$format);
}

#[AsTask(name: 'tools:inspect', namespace: 'mate-tools', description: 'Inspect one Mate MCP tool (schema + metadata); formats: text, json, toon')]
function tooling_inspect(string $toolName, string $format = 'json'): void
{
    mate_tools_validate_tool_name($toolName);
    $format = mate_tools_validate_format($format, ['text', 'json', 'toon']);
    dev_php_exec(sprintf(
        'php vendor/bin/mate mcp:tools:inspect %s --format=%s',
        escapeshellarg($toolName),
        $format,
    ));
}
