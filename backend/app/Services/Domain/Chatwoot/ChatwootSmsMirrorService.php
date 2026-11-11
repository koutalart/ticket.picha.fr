<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\ChatwootUnavailableException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Copies every SMS of PICHA into the « SMS PICHA » inbox of Chatwoot, one Chatwoot conversation per SMS conversation.
 * Replies typed in Chatwoot come back through the webhook and are sent as SMS by PICHA.
 */
class ChatwootSmsMirrorService
{
    private const PENDING_TTL_SECONDS = 300;

    private static bool $suppressed = false;

    public function __construct(
        private readonly ChatwootClient $client,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
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

    public static function pendingKey(int $chatwootConversationId, string $content): string
    {
        return 'chatwoot:sms-mirror:'.$chatwootConversationId.':'.sha1(trim($content));
    }

    public function isEnabled(): bool
    {
        return $this->client->isSmsGatewayEnabled();
    }

    public function shouldMirror(SmsMessageDomainObject $message): bool
    {
        return ! $message->getIsSupervision()
            && $message->getChannel() === null
            && $message->getChatwootMessageId() === null
            && trim((string) $message->getContent()) !== '';
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function mirror(int $messageId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var SmsMessageDomainObject|null $message */
        $message = $this->messageRepository->findFirstWhere([SmsMessageDomainObjectAbstract::ID => $messageId]);
        if ($message === null || ! $this->shouldMirror($message)) {
            return;
        }

        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $message->getSmsConversationId(),
        ]);
        if ($conversation === null) {
            return;
        }

        $chatwootConversationId = $conversation->getChatwootConversationId() ?? $this->openConversation($conversation);
        $incoming = $message->getDirection() === SmsMessageDirection::INBOUND->name;
        $content = $incoming ? (string) $message->getContent() : $this->outgoingContent($message);

        if (! $incoming) {
            $this->cache->put(self::pendingKey($chatwootConversationId, $content), true, self::PENDING_TTL_SECONDS);
        }

        $chatwootMessageId = $this->client->createMessage($chatwootConversationId, $content, $incoming);

        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $chatwootMessageId,
        ]);
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function noteFailure(int $messageId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var SmsMessageDomainObject|null $message */
        $message = $this->messageRepository->findFirstWhere([SmsMessageDomainObjectAbstract::ID => $messageId]);
        if ($message === null || $message->getChatwootMessageId() === null) {
            return;
        }

        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $message->getSmsConversationId(),
        ]);
        if ($conversation?->getChatwootConversationId() === null) {
            return;
        }

        $this->client->createMessage(
            $conversation->getChatwootConversationId(),
            __('SMS not delivered: :content', ['content' => mb_substr((string) $message->getContent(), 0, 80)])
                .($message->getErrorMessage() ? ' ('.$message->getErrorMessage().')' : ''),
            incoming: false,
            private: true,
        );
    }

    public function postNote(int $chatwootConversationId, string $note): void
    {
        try {
            $this->client->createMessage($chatwootConversationId, $note, incoming: false, private: true);
        } catch (ChatwootUnavailableException $exception) {
            logger()->warning('Chatwoot note not posted', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @throws ChatwootUnavailableException
     */
    private function openConversation(SmsConversationDomainObject $conversation): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $conversation->getOrganizerId()]);
        $organizerName = $organizer?->getName() ?? (string) $conversation->getOrganizerId();

        $contactId = $this->client->findOrCreateContact(
            $conversation->getPhone(),
            $conversation->getContactName() ?: $conversation->getPhone(),
        );

        $chatwootConversationId = $this->client->createConversation($contactId, 'picha-sms-live-'.$conversation->getId(), [
            'organisateur' => $organizerName,
            'conversation_picha' => $conversation->getId(),
        ]);

        $this->conversationRepository->updateFromArray($conversation->getId(), [
            SmsConversationDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId,
        ]);

        $this->client->createMessage(
            $chatwootConversationId,
            __('PICHA SMS conversation :id, organizer: :organizer', ['id' => $conversation->getId(), 'organizer' => $organizerName])
                .($conversation->getIsTeam() ? ' ('.__('team').')' : ''),
            incoming: false,
            private: true,
        );

        return $chatwootConversationId;
    }

    private function outgoingContent(SmsMessageDomainObject $message): string
    {
        $author = match ($message->getAuthorType()) {
            SmsMessageAuthorType::AGENT->name => 'Anicka',
            SmsMessageAuthorType::CAMPAIGN->name => __('Campaign'),
            SmsMessageAuthorType::AUTOMATIC->name => __('Automatic'),
            default => null,
        };

        return ($author !== null ? '['.$author.'] ' : '').$message->getContent();
    }
}
