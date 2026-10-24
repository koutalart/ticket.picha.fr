<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use HiEvents\Services\Infrastructure\AI\DTO\ClaudeStructuredAnswerDTO;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * One Claude call that must answer with JSON matching a schema.
 */
class ClaudeStructuredOutputClient
{
    private const MAX_TOKENS = 8000;

    /** Server tools (web search) may pause a long turn: resume it at most this many times. */
    private const MAX_CONTINUATIONS = 3;

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->config->get('services.anthropic.api_key')) !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $content  User content blocks (text, image)
     * @param  array<string, mixed>  $schema  JSON schema of the expected answer
     * @param  array<int, array<string, mixed>>  $tools  Server tools Claude may use (e.g. web search)
     * @return array<string, mixed>
     *
     * @throws EventAssistantUnavailableException
     * @throws EventAssistantFailedException
     */
    public function ask(string $system, array $content, array $schema, array $tools = []): array
    {
        return $this->askWithUsage($system, $content, $schema, $tools)->data;
    }

    /**
     * Same as ask(), with the model and token usage of the answer. $model overrides the configured model (e.g. a smaller one).
     *
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @param  array<int, array<string, mixed>>  $tools
     *
     * @throws EventAssistantUnavailableException
     * @throws EventAssistantFailedException
     */
    public function askWithUsage(string $system, array $content, array $schema, array $tools = [], ?string $model = null, ?int $maxTokens = null): ClaudeStructuredAnswerDTO
    {
        if (! $this->isConfigured()) {
            throw new EventAssistantUnavailableException(__('The assistant is not available.'));
        }

        $client = new Client(apiKey: (string) $this->config->get('services.anthropic.api_key'));
        $model = $model !== null && trim($model) !== '' ? trim($model) : (string) $this->config->get('services.anthropic.model');
        $withFallbacks = self::supportsServerSideFallback($model);
        $messages = [['role' => 'user', 'content' => $content]];
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            for ($attempt = 0; $attempt <= self::MAX_CONTINUATIONS; $attempt++) {
                $message = $client->beta->messages->create(
                    model: $model,
                    maxTokens: $maxTokens ?? self::MAX_TOKENS,
                    system: $system,
                    messages: $messages,
                    outputConfig: [
                        'effort' => 'low',
                        'format' => ['type' => 'json_schema', 'schema' => $schema],
                    ],
                    fallbacks: $withFallbacks ? 'default' : null,
                    tools: $tools === [] ? null : $tools,
                    betas: $withFallbacks ? ['server-side-fallback-2026-07-01'] : null,
                );

                $inputTokens += $message->usage->inputTokens;
                $outputTokens += $message->usage->outputTokens;

                if ($message->stopReason !== 'pause_turn') {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $message->content];
            }
        } catch (AnthropicException $exception) {
            $this->logger->error('Event assistant: Claude request failed', ['exception' => $exception]);

            throw new EventAssistantFailedException(__('The assistant could not answer. Please try again or fill in the form yourself.'));
        }

        if ($message->stopReason !== 'end_turn') {
            $this->logger->warning('Event assistant: unexpected stop reason', ['stop_reason' => $message->stopReason]);

            throw new EventAssistantFailedException(__('The assistant could not answer. Please try again or fill in the form yourself.'));
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return new ClaudeStructuredAnswerDTO(
                        data: $data,
                        model: $message->model,
                        inputTokens: $inputTokens,
                        outputTokens: $outputTokens,
                    );
                }
            }
        }

        throw new EventAssistantFailedException(__('The assistant could not answer. Please try again or fill in the form yourself.'));
    }

    /**
     * Haiku models have no server-side refusal fallback.
     */
    public static function supportsServerSideFallback(string $model): bool
    {
        return ! str_starts_with($model, 'claude-haiku');
    }
}
