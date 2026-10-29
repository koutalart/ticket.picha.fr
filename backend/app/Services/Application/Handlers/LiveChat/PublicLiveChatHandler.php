<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\LiveChat;

use HiEvents\Exceptions\LiveChatInvalidContactException;
use HiEvents\Exceptions\LiveChatLimitReachedException;
use HiEvents\Exceptions\LiveChatUnavailableException;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatConfigDTO;
use HiEvents\Services\Domain\LiveChat\DTO\LiveChatTurnDTO;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use Throwable;

/**
 * Entry point of the public web chat endpoints (no authentication).
 */
class PublicLiveChatHandler
{
    public function __construct(
        private readonly LiveChatService $liveChatService,
    ) {}

    public function getConfig(int $organizerId, ?int $eventId = null): LiveChatConfigDTO
    {
        return $this->liveChatService->getConfig($organizerId, $eventId);
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     */
    public function start(int $organizerId, ?int $eventId, string $ip): LiveChatTurnDTO
    {
        return $this->liveChatService->start($organizerId, $eventId, $ip);
    }

    /**
     * @throws LiveChatUnavailableException
     */
    public function messages(string $sessionId): LiveChatTurnDTO
    {
        return $this->liveChatService->messages($sessionId);
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatLimitReachedException
     * @throws Throwable
     */
    public function post(string $sessionId, string $message, string $ip): LiveChatTurnDTO
    {
        return $this->liveChatService->post($sessionId, $message, $ip);
    }

    /**
     * @throws LiveChatUnavailableException
     * @throws LiveChatInvalidContactException
     * @throws Throwable
     */
    public function submitContact(string $sessionId, ?string $phone, ?string $email): LiveChatTurnDTO
    {
        return $this->liveChatService->submitContact($sessionId, $phone, $email);
    }
}
