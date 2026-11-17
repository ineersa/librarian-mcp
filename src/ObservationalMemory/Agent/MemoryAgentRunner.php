<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

use App\ObservationalMemory\MemorySettings;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Generic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
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
        private MemorySettings $settings,
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
        ?string $thinkingLevel = null,
    ): void {
        $platform = GenericFactory::createPlatform(
            baseUrl: $this->settings->modelUrl,
            httpClient: HttpClient::create([
                'timeout' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
                'max_duration' => (float) MemorySettings::AGENT_HTTP_MAX_DURATION_SECONDS,
                'extra' => ['trace_content' => false],
            ]),
            modelCatalog: new ModelCatalog([
                $this->settings->providerModelName => [
                    'class' => CompletionsModel::class,
                    'capabilities' => [
                        Capability::INPUT_MESSAGES,
                        Capability::OUTPUT_TEXT,
                        Capability::TOOL_CALLING,
                    ],
                ],
            ]),
            supportsEmbeddings: false,
            completionsPath: self::completionsPath($this->settings->modelUrl),
            name: 'librarian_om',
        );

        $agent = new Agent(
            platform: $platform,
            model: $this->settings->providerModelName,
            name: 'librarian-om',
            toolbox: [] === $tools ? null : new FaultTolerantToolbox(new MemoryAgentToolbox($tools)),
            maxToolCalls: $maxToolCalls,
        );

        $options = [];
        if (self::THINKING_OFF === $thinkingLevel && MemorySettings::THINKING_FORMAT_LLAMA_CPP === $this->settings->thinkingFormat) {
            // Explicit per-call off only (Dropper): disables the reasoning phase.
            $options['chat_template_kwargs'] = ['enable_thinking' => false];
        }

        $context = [
            'component' => 'observational_memory',
            'run_id' => $sessionId,
            'correlation_id' => $correlationId,
            'model' => $this->settings->model,
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

    private static function completionsPath(string $baseUrl): string
    {
        return str_ends_with(rtrim($baseUrl, '/'), '/v1') ? '/chat/completions' : '/v1/chat/completions';
    }
}
