<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

use App\ObservationalMemory\MemorySettings;
use App\ObservationalMemory\Model\MemoryModelCatalog;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryReasoningLevel;
use App\ObservationalMemory\Model\MemoryThinkingFormat;
use App\ObservationalMemory\Model\PinnedMemorySettings;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Bridge\Generic\Completions\ResultConverter;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog as GenericModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\Factory as OpenResponsesFactory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog as OpenResponsesModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
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

    private const string OPENCODE_SESSION_HEADER = 'x-opencode-session';
    private const string OPENCODE_CLIENT_HEADER = 'x-opencode-client';
    private const string OPENCODE_CLIENT = 'hatfield';

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
        $platform = $this->createPlatform($settings, $sessionId);
        $agent = new Agent(
            platform: $platform,
            model: $settings->providerModelName,
            name: 'librarian-om',
            toolbox: [] === $tools ? null : new FaultTolerantToolbox(new MemoryAgentToolbox($tools)),
            maxToolCalls: $maxToolCalls,
        );

        $effectiveThinking = $thinkingLevel ?? $settings->reasoningLevel->value;
        if (self::THINKING_OFF === $effectiveThinking && !$settings->supportsTrueOff) {
            $effectiveThinking = match ($settings->thinkingFormat) {
                MemoryThinkingFormat::None => MemoryReasoningLevel::Default->value,
                default => MemoryReasoningLevel::Low->value,
            };
        }
        $options = $this->reasoningOptions($settings, $effectiveThinking);
        // The buffered converters discard thinking on tool-call turns. The
        // streaming runner replays the complete assistant turn; getResult()
        // still drains the execution before the Messenger job can finish.
        if (MemoryThinkingFormat::Deepseek === $settings->thinkingFormat
            || MemoryThinkingFormat::ChatGpt === $settings->thinkingFormat
            || MemoryThinkingFormat::ReasoningEffort === $settings->thinkingFormat
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
        if (MemoryReasoningLevel::Default->value === $thinkingLevel) {
            return [];
        }

        return match ($settings->thinkingFormat) {
            MemoryThinkingFormat::None => [],
            MemoryThinkingFormat::LlamaCpp, MemoryThinkingFormat::ReasoningEffort => [
                'reasoning_effort' => self::THINKING_OFF === $thinkingLevel ? MemoryThinkingFormat::None->value : $thinkingLevel,
            ],
            MemoryThinkingFormat::Deepseek => [
                'thinking' => ['type' => 'enabled'],
                'reasoning_effort' => $thinkingLevel,
            ],
            MemoryThinkingFormat::ChatGpt => ['reasoning' => ['effort' => $thinkingLevel, 'summary' => 'auto']],
            MemoryThinkingFormat::Zai => [
                'thinking' => [
                    'type' => 'enabled',
                    'clear_thinking' => false,
                ],
                'reasoning_effort' => $thinkingLevel,
            ],
        };
    }

    private function createPlatform(PinnedMemorySettings $settings, string $sessionId): PlatformInterface
    {
        $httpClient = HttpClient::create([
            'timeout' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
            'max_duration' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
            'extra' => ['trace_content' => false],
        ]);

        $baseUrl = $settings->baseUrl ?? throw new \InvalidArgumentException('OM bridge requires a base URL.');
        $apiKey = $this->selections->apiKeyFor($settings);
        $isOpenCodeGo = MemoryModelCatalog::OPENCODE_GO_BASE_URL === $baseUrl && MemoryModelCatalog::ENV_OPENCODE_API_KEY === $settings->apiKeyEnv;
        if ($isOpenCodeGo) {
            if (null === $apiKey || '' === $sessionId) {
                throw new \InvalidArgumentException('OpenCode Go requires an API key and a run ID.');
            }
            // Reuse the run ID on every tool round, as Agent Core does.
            $httpClient = $httpClient->withOptions([
                'max_redirects' => 0,
                'headers' => [
                    self::OPENCODE_SESSION_HEADER => $sessionId,
                    self::OPENCODE_CLIENT_HEADER => self::OPENCODE_CLIENT,
                    'User-Agent' => self::OPENCODE_CLIENT,
                ],
            ]);
        }

        $capabilities = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::TOOL_CALLING];
        if ($isOpenCodeGo && MemoryModelCatalog::OPENCODE_GO_RESPONSES_PATH === $settings->completionsPath) {
            return OpenResponsesFactory::createPlatform(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
                modelCatalog: new OpenResponsesModelCatalog([
                    $settings->providerModelName => ['class' => ResponsesModel::class, 'capabilities' => $capabilities],
                ]),
                responsesPath: $settings->completionsPath,
                name: 'librarian_om',
            );
        }

        $catalog = new GenericModelCatalog([
            $settings->providerModelName => ['class' => CompletionsModel::class, 'capabilities' => $capabilities],
        ]);
        if ($isOpenCodeGo && MemoryThinkingFormat::Deepseek === $settings->thinkingFormat) {
            // The installed Generic converter already keeps only the final cumulative usage snapshot.
            return new Platform([new Provider(
                'librarian_om',
                [new OpenCodeDeepseekModelClient($httpClient, $baseUrl, $apiKey, $settings->completionsPath)],
                [new ResultConverter()],
                $catalog,
            )]);
        }

        return GenericFactory::createPlatform(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            modelCatalog: $catalog,
            supportsEmbeddings: false,
            completionsPath: $settings->completionsPath,
            name: 'librarian_om',
        );
    }
}
