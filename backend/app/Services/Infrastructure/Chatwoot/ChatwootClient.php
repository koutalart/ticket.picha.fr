<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Chatwoot;

use HiEvents\Exceptions\ChatwootUnavailableException;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ChatwootClient
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function isSmsGatewayEnabled(): bool
    {
        return $this->config->get('services.chatwoot.base_url')
            && $this->config->get('services.chatwoot.api_token')
            && $this->smsInboxId() > 0;
    }

    public function smsInboxId(): int
    {
        return (int) $this->config->get('services.chatwoot.sms_inbox_id');
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function findOrCreateContact(string $phone, string $name): int
    {
        $found = $this->call('GET', 'contacts/search', ['q' => $phone])->json('payload') ?? [];
        foreach ($found as $contact) {
            if (($contact['phone_number'] ?? null) === $phone) {
                return (int) $contact['id'];
            }
        }

        return (int) $this->call('POST', 'contacts', ['name' => $name, 'phone_number' => $phone])->json('payload.contact.id');
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function createConversation(int $contactId, string $sourceId, array $attributes): int
    {
        $this->call('POST', "contacts/{$contactId}/contact_inboxes", [
            'inbox_id' => $this->smsInboxId(),
            'source_id' => $sourceId,
        ]);

        return (int) $this->call('POST', 'conversations', [
            'source_id' => $sourceId,
            'inbox_id' => $this->smsInboxId(),
            'contact_id' => $contactId,
            'status' => 'open',
            'additional_attributes' => $attributes,
        ])->json('id');
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function createMessage(int $conversationId, string $content, bool $incoming, bool $private = false): int
    {
        return (int) $this->call('POST', "conversations/{$conversationId}/messages", [
            'content' => $content,
            'message_type' => $incoming ? 'incoming' : 'outgoing',
            'private' => $private,
        ])->json('id');
    }

    /**
     * @throws ChatwootUnavailableException
     */
    private function call(string $method, string $path, array $body): Response
    {
        $url = rtrim((string) $this->config->get('services.chatwoot.base_url'), '/')
            .'/api/v1/accounts/'.(int) $this->config->get('services.chatwoot.account_id').'/'.$path;

        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['api_access_token' => (string) $this->config->get('services.chatwoot.api_token')])
            ->connectTimeout(3)
            ->timeout((int) $this->config->get('services.chatwoot.timeout_seconds', 10));

        try {
            $response = $method === 'GET' ? $request->get($url, $body) : $request->post($url, $body);
        } catch (ConnectionException $exception) {
            throw new ChatwootUnavailableException('Chatwoot unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new ChatwootUnavailableException("Chatwoot {$method} {$path} -> {$response->status()} ".mb_substr($response->body(), 0, 300));
        }

        return $response;
    }
}
