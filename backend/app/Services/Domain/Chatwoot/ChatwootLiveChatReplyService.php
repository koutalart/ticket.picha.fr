<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\Exceptions\ChatwootUnavailableException;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use HiEvents\Services\Infrastructure\UneAnicka\UneAnickaClient;

/**
 * A human answered a chat of an event page from Chatwoot: the answer is shown to the visitor in the chat, and the router
 * of Anicka learns that a human is talking to this person (no automatic reply over them).
 */
class ChatwootLiveChatReplyService
{
    public function __construct(
        private readonly ChatwootLiveChatMirrorService $mirrorService,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly LiveChatService $liveChatService,
        private readonly ChatwootClient $client,
        private readonly UneAnickaClient $router,
    ) {}

    public function isLiveChatInbox(int $inboxId): bool
    {
        return $inboxId > 0 && $inboxId === $this->client->liveChatInboxId();
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function relay(int $chatwootConversationId, string $content): void
    {
        $sessionId = $this->mirrorService->sessionIdFor($chatwootConversationId);
        if ($sessionId === null) {
            $this->client->createMessage($chatwootConversationId, __('Not shown to the visitor: this chat is too old or unknown.'), incoming: false, private: true);

            return;
        }

        /** @var LiveChatSessionDomainObject $session */
        $session = $this->sessionRepository->findById($sessionId);
        ChatwootLiveChatMirrorService::withoutMirroring(fn () => $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $content));

        $this->router->decide(
            'humain-chatwoot',
            $session->getVisitorPhone() ?? $session->getVisitorEmail() ?? 'chat:'.$session->getPublicId(),
            'chat',
            $content,
            $session->getOrganizerId(),
            kind: 'humain',
        );
    }
}
