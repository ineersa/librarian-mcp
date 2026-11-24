<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

use App\ObservationalMemory\MemorySettings;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryReasoningLevel;
use App\ObservationalMemory\Model\MemoryThinkingFormat;
use App\ObservationalMemory\Model\PinnedMemorySettings;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog as GenericModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Port of Hatfield ConfiguredModelAgentRunner for OM Observer/Reflector/Dropper calls.
 *
 * One blocking Agent call: system instructions + user input, in-process tools wrapped
 * in FaultTolerantToolbox, bounded tool calls and per-request max duration.
 */
final readonly class MemoryAgentRunner
{
    public const string THINKING_OFF = 'off';

    public function __construct(
        private MemoryModelSelectionService $selections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<MemoryAgentTool> $tools
     */
    public function run(
        string $sessionId,
        string $instructions,
        string $input,
        array $tools,
        ?string $correlationId,
        int $maxToolCalls,
        PinnedMemorySettings $settings,
        ?string $thinkingLevel = null,
    ): void {
        $platform = $this->createPlatform($settings);
        $agent = new Agent(
            platform: $platform,
            model: $settings->providerModelName,
            name: 'librarian-om',
            toolbox: [] === $tools ? null : new FaultTolerantToolbox(new MemoryAgentToolbox($tools)),
            maxToolCalls: $maxToolCalls,
        );

        $effectiveThinking = $thinkingLevel ?? $settings->reasoningLevel->value;
        if (self::THINKING_OFF === $effectiveThinking && !$settings->supportsTrueOff) {
            $effectiveThinking = MemoryThinkingFormat::None === $settings->thinkingFormat
                ? MemoryReasoningLevel::Default->value
                : MemoryReasoningLevel::Low->value;
        }
        $options = $this->reasoningOptions($settings, $effectiveThinking);
        // The buffered converters discard thinking on tool-call turns. The
        // streaming runner replays the complete assistant turn; getResult()
        // still drains the execution before the Messenger job can finish.
        if (MemoryThinkingFormat::Deepseek === $settings->thinkingFormat
            || MemoryThinkingFormat::ChatGpt === $settings->thinkingFormat
            || MemoryThinkingFormat::Zai === $settings->thinkingFormat) {
            $options['stream'] = true;
        }
        if (MemoryThinkingFormat::Zai === $settings->thinkingFormat) {
            $options['tool_stream'] = true;
        }

        $context = [
            'component' => 'observational_memory',
            'run_id' => $sessionId,
            'correlation_id' => $correlationId,
            'model' => $settings->model,
            'preset_id' => $settings->presetId,
            'reasoning_level' => $effectiveThinking,
            'tool_count' => \count($tools),
        ];
        $this->logger->info('om.agent.run.started', $context + ['event_type' => 'om.agent.run.started']);
        try {
            $agent->call(new MessageBag(
                Message::forSystem($instructions),
                Message::ofUser($input),
            ), $options)->getResult();
        } catch (\Throwable $e) {
            // Privacy: exception class only; messages may contain prompts/tool output.
            $this->logger->error('om.agent.run.failed', $context + [
                'event_type' => 'om.agent.run.failed',
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
        $this->logger->info('om.agent.run.completed', $context + ['event_type' => 'om.agent.run.completed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reasoningOptions(PinnedMemorySettings $settings, string $thinkingLevel): array
    {
        return match ($settings->thinkingFormat) {
            MemoryThinkingFormat::None => [],
            MemoryThinkingFormat::LlamaCpp => self::THINKING_OFF === $thinkingLevel
                ? ['chat_template_kwargs' => ['enable_thinking' => false]]
                : [],
            MemoryThinkingFormat::Deepseek, MemoryThinkingFormat::ChatGpt => [
                'reasoning_effort' => self::THINKING_OFF === $thinkingLevel ? 'none' : $thinkingLevel,
            ],
            MemoryThinkingFormat::Zai => [
                'thinking' => [
                    'type' => 'enabled',
                    'clear_thinking' => false,
                ],
                'reasoning_effort' => $thinkingLevel,
            ],
        };
    }

    private function createPlatform(PinnedMemorySettings $settings): PlatformInterface
    {
        $httpClient = HttpClient::create([
            'timeout' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
            'max_duration' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
            'extra' => ['trace_content' => false],
        ]);

        return GenericFactory::createPlatform(
            baseUrl: $settings->baseUrl ?? throw new \InvalidArgumentException('Generic OM bridge requires a base URL.'),
            apiKey: $this->selections->apiKeyFor($settings),
            httpClient: $httpClient,
            modelCatalog: new GenericModelCatalog([
                $settings->providerModelName => [
                    'class' => CompletionsModel::class,
                    'capabilities' => [
                        Capability::INPUT_MESSAGES,
                        Capability::OUTPUT_TEXT,
                        Capability::TOOL_CALLING,
                    ],
                ],
            ]),
            supportsEmbeddings: false,
            completionsPath: $settings->completionsPath,
            name: 'librarian_om',
        );
    }
}
