<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Generated\LiveChatMessageDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ChatwootUnavailableException;
use HiEvents\Repository\Interfaces\LiveChatMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Copies the chat of the event pages into the live chat inbox of Chatwoot, one Chatwoot conversation per chat session,
 * so that every exchange of Anicka is in Chatwoot. A reply typed there by a human is shown to the visitor in the chat.
 */
class ChatwootLiveChatMirrorService
{
    private const LINK_TTL_SECONDS = 60 * 24 * 3600;

    private static bool $suppressed = false;

    public function __construct(
        private readonly ChatwootClient $client,
        private readonly LiveChatMessageRepositoryInterface $messageRepository,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly Cache $cache,
    ) {}

    public static function withoutMirroring(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    public static function sessionKey(int $sessionId): string
    {
        return 'chatwoot:live-chat:session:'.$sessionId;
    }

    public static function conversationKey(int $chatwootConversationId): string
    {
        return 'chatwoot:live-chat:conversation:'.$chatwootConversationId;
    }

    public function isEnabled(): bool
    {
        return $this->client->isLiveChatEnabled();
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function mirror(int $messageId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var LiveChatMessageDomainObject|null $message */
        $message = $this->messageRepository->findFirstWhere([LiveChatMessageDomainObjectAbstract::ID => $messageId]);
        if ($message === null || trim((string) $message->getContent()) === '') {
            return;
        }

        /** @var LiveChatSessionDomainObject $session */
        $session = $this->sessionRepository->findById($message->getLiveChatSessionId());
        $chatwootConversationId = $this->cache->get(self::sessionKey($session->getId())) ?? $this->openConversation($session);
        $incoming = $message->getAuthorType() === LiveChatAuthorType::VISITOR->name;

        $this->client->createMessage($chatwootConversationId, (string) $message->getContent(), $incoming);
    }

    public function sessionIdFor(int $chatwootConversationId): ?int
    {
        $sessionId = $this->cache->get(self::conversationKey($chatwootConversationId));

        return $sessionId === null ? null : (int) $sessionId;
    }

    /**
     * @throws ChatwootUnavailableException
     */
    private function openConversation(LiveChatSessionDomainObject $session): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $session->getOrganizerId()]);
        $organizerName = $organizer?->getName() ?? (string) $session->getOrganizerId();

        $contactId = $this->client->findOrCreateVisitorContact(
            $session->getVisitorPhone(),
            $session->getVisitorEmail() !== null ? strtolower($session->getVisitorEmail()) : null,
            __('Chat visitor (:organizer)', ['organizer' => $organizerName]),
        );

        $chatwootConversationId = $this->client->createConversation($contactId, 'picha-chat-'.$session->getId(), [
            'organisateur' => $organizerName,
            'chat_picha' => $session->getPublicId(),
        ], $this->client->liveChatInboxId());

        $this->cache->put(self::sessionKey($session->getId()), $chatwootConversationId, self::LINK_TTL_SECONDS);
        $this->cache->put(self::conversationKey($chatwootConversationId), $session->getId(), self::LINK_TTL_SECONDS);

        $this->client->createMessage(
            $chatwootConversationId,
            __('Chat of the event page, organizer: :organizer', ['organizer' => $organizerName]),
            incoming: false,
            private: true,
        );

        return $chatwootConversationId;
    }
}
