<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Port of Hatfield IsolatedAgentToolbox: per-call in-process OM tools with raw JSON arguments.
 */
final class MemoryAgentToolbox implements ToolboxInterface
{
    /** @var array<string, Tool> */
    private array $metadataByName = [];

    /** @var array<string, MemoryToolHandlerInterface> */
    private array $handlersByName = [];

    /**
     * @param list<MemoryAgentTool> $tools
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            if (isset($this->handlersByName[$tool->name])) {
                throw new \InvalidArgumentException(\sprintf('Duplicate OM agent tool name "%s".', $tool->name));
            }

            $this->handlersByName[$tool->name] = $tool->handler;
            $this->metadataByName[$tool->name] = new Tool(
                reference: new ExecutionReference($tool->handler::class, '__invoke'),
                name: $tool->name,
                description: $tool->description,
                parameters: $tool->parametersJsonSchema,
            );
        }
    }

    public function getTools(): array
    {
        return array_values($this->metadataByName);
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $handler = $this->handlersByName[$toolCall->getName()] ?? null;
        if (null === $handler) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        return new ToolResult($toolCall, $handler($toolCall->getArguments()));
    }
}
