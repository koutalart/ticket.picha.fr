<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * One Claude call that must answer with JSON matching a schema.
 */
class ClaudeStructuredOutputClient
{
    private const MAX_TOKENS = 8000;

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
     * @return array<string, mixed>
     *
     * @throws EventAssistantUnavailableException
     * @throws EventAssistantFailedException
     */
    public function ask(string $system, array $content, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw new EventAssistantUnavailableException(__('The assistant is not available.'));
        }

        try {
            $message = (new Client(apiKey: (string) $this->config->get('services.anthropic.api_key')))
                ->beta->messages->create(
                    model: (string) $this->config->get('services.anthropic.model'),
                    maxTokens: self::MAX_TOKENS,
                    system: $system,
                    messages: [['role' => 'user', 'content' => $content]],
                    outputConfig: [
                        'effort' => 'low',
                        'format' => ['type' => 'json_schema', 'schema' => $schema],
                    ],
                    fallbacks: 'default',
                    betas: ['server-side-fallback-2026-07-01'],
                );
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
                    return $data;
                }
            }
        }

        throw new EventAssistantFailedException(__('The assistant could not answer. Please try again or fill in the form yourself.'));
    }
}
