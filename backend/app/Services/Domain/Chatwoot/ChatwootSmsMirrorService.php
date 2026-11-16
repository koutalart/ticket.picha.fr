<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsUnattributedMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsUnattributedOutboundMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\DomainObjects\SmsUnattributedOutboundMessageDomainObject;
use HiEvents\Exceptions\ChatwootUnavailableException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\RepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedOutboundMessageRepositoryInterface;
use HiEvents\Services\Infrastructure\Chatwoot\ChatwootClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Copies every SMS of PICHA into the « SMS PICHA » inbox of Chatwoot, one Chatwoot conversation per SMS conversation.
 * SMS of numbers without a conversation and SMS that Anicka's scripts send for no organizer get their own Chatwoot
 * conversation, one per number.
 * Replies typed in Chatwoot come back through the webhook and are sent as SMS by PICHA.
 */
class ChatwootSmsMirrorService
{
    private const PENDING_TTL_SECONDS = 300;

    private const RECENT_COPY_WINDOW_SECONDS = 600;

    private static bool $suppressed = false;

    public function __construct(
        private readonly ChatwootClient $client,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly Cache $cache,
        private readonly SmsUnattributedMessageRepositoryInterface $unattributedRepository,
        private readonly SmsUnattributedOutboundMessageRepositoryInterface $outboundRepository,
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

        if ($this->reuseUnattributedCopy($message, $conversation)) {
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
    public function mirrorUnattributed(int $unattributedId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var SmsUnattributedMessageDomainObject|null $message */
        $message = $this->unattributedRepository->findFirstWhere([SmsUnattributedMessageDomainObjectAbstract::ID => $unattributedId]);
        if ($message === null
            || $message->getSmsMessageId() !== null
            || $message->getChatwootMessageId() !== null
            || trim($message->getContent()) === '') {
            return;
        }

        $chatwootConversationId = $this->numberConversationId($message->getPhone());
        $chatwootMessageId = $this->findRecentCopy($chatwootConversationId, $message->getContent())
            ?? $this->client->createMessage($chatwootConversationId, $message->getContent(), true);

        $this->unattributedRepository->updateFromArray($message->getId(), [
            SmsUnattributedMessageDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId,
            SmsUnattributedMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $chatwootMessageId,
        ]);
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function mirrorUnattributedOutbound(int $outboundId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var SmsUnattributedOutboundMessageDomainObject|null $message */
        $message = $this->outboundRepository->findFirstWhere([SmsUnattributedOutboundMessageDomainObjectAbstract::ID => $outboundId]);
        if ($message === null || $message->getChatwootMessageId() !== null || trim($message->getContent()) === '') {
            return;
        }

        $chatwootConversationId = $this->numberConversationId($message->getPhone());
        $content = '[Anicka] '.$message->getContent();
        $chatwootMessageId = $this->findRecentCopy($chatwootConversationId, $content);

        if ($chatwootMessageId === null) {
            $this->cache->put(self::pendingKey($chatwootConversationId, $content), true, self::PENDING_TTL_SECONDS);
            $chatwootMessageId = $this->client->createMessage($chatwootConversationId, $content, false);
        }

        $this->outboundRepository->updateFromArray($message->getId(), [
            SmsUnattributedOutboundMessageDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId,
            SmsUnattributedOutboundMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $chatwootMessageId,
        ]);
    }

    /**
     * @throws ChatwootUnavailableException
     */
    public function noteUnattributedOutboundFailure(int $outboundId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        /** @var SmsUnattributedOutboundMessageDomainObject|null $message */
        $message = $this->outboundRepository->findFirstWhere([SmsUnattributedOutboundMessageDomainObjectAbstract::ID => $outboundId]);
        if ($message?->getChatwootConversationId() === null) {
            return;
        }

        $this->client->createMessage(
            $message->getChatwootConversationId(),
            __('SMS not delivered: :content', ['content' => mb_substr($message->getContent(), 0, 80)])
                .($message->getErrorMessage() ? ' ('.$message->getErrorMessage().')' : ''),
            incoming: false,
            private: true,
        );
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

        $chatwootConversationId = $this->firstUnlinked($this->smsConversationIds($contactId))
            ?? $this->client->createConversation($contactId, 'picha-sms-live-'.$conversation->getId(), [
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

    /**
     * An SMS first kept for the platform, then given to an organizer, is already in Chatwoot: it is not copied twice,
     * and the organizer conversation goes on in the Chatwoot conversation of the number when it has none yet.
     *
     * @throws ChatwootUnavailableException
     */
    private function reuseUnattributedCopy(SmsMessageDomainObject $message, SmsConversationDomainObject $conversation): bool
    {
        if ($message->getDirection() !== SmsMessageDirection::INBOUND->name || $message->getProviderMessageId() === null) {
            return false;
        }

        /** @var SmsUnattributedMessageDomainObject|null $copy */
        $copy = $this->unattributedRepository->findFirstWhere([
            SmsUnattributedMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $message->getProviderMessageId(),
            [SmsUnattributedMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID, 'not null', null],
        ]);
        if ($copy === null) {
            return false;
        }

        if ($conversation->getChatwootConversationId() === null) {
            $this->conversationRepository->updateFromArray($conversation->getId(), [
                SmsConversationDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $copy->getChatwootConversationId(),
            ]);

            /** @var OrganizerDomainObject|null $organizer */
            $organizer = $this->organizerRepository->findFirstWhere(['id' => $conversation->getOrganizerId()]);
            $this->client->createMessage(
                (int) $copy->getChatwootConversationId(),
                __('PICHA SMS conversation :id, organizer: :organizer', [
                    'id' => $conversation->getId(),
                    'organizer' => $organizer?->getName() ?? (string) $conversation->getOrganizerId(),
                ]),
                incoming: false,
                private: true,
            );
        }

        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $copy->getChatwootMessageId(),
        ]);

        return true;
    }

    /**
     * The Chatwoot conversation of a number that belongs to no organizer: the one already used for its SMS, else a
     * conversation of the contact in the SMS inbox (preferably one no organizer conversation uses, like those a script
     * of Anicka opened), else a new one. A number never gets two threads.
     *
     * @throws ChatwootUnavailableException
     */
    private function numberConversationId(string $phone): int
    {
        $known = $this->lastConversationId($this->unattributedRepository, SmsUnattributedMessageDomainObjectAbstract::PHONE, $phone)
            ?? $this->lastConversationId($this->outboundRepository, SmsUnattributedOutboundMessageDomainObjectAbstract::PHONE, $phone);
        if ($known !== null) {
            return $known;
        }

        $contactId = $this->client->findOrCreateContact($phone, $phone);

        $threads = $this->smsConversationIds($contactId);
        $existing = $this->firstUnlinked($threads) ?? $threads[0] ?? null;
        if ($existing !== null) {
            return $existing;
        }

        $chatwootConversationId = $this->client->createConversation($contactId, 'picha-sms-inconnu-'.ltrim($phone, '+'), [
            'organisateur' => __('no organizer'),
            'numero_sans_conversation' => true,
        ]);

        $this->client->createMessage(
            $chatwootConversationId,
            __('SMS from a number without a PICHA conversation. A reply typed here is sent by SMS from the same line.'),
            incoming: false,
            private: true,
        );

        return $chatwootConversationId;
    }

    /**
     * Conversations of the contact in the SMS inbox, newest first, whatever their source (PICHA, a script of Anicka…),
     * so a number keeps a single thread.
     *
     * @return array<int, int>
     *
     * @throws ChatwootUnavailableException
     */
    private function smsConversationIds(int $contactId): array
    {
        return collect($this->client->contactConversations($contactId))
            ->filter(fn (array $conversation) => $conversation['inbox_id'] === $this->client->smsInboxId())
            ->pluck('id')
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $chatwootConversationIds
     */
    private function firstUnlinked(array $chatwootConversationIds): ?int
    {
        foreach ($chatwootConversationIds as $chatwootConversationId) {
            if ($this->conversationRepository->findFirstWhere([
                SmsConversationDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId,
            ]) === null) {
                return $chatwootConversationId;
            }
        }

        return null;
    }

    private function lastConversationId(RepositoryInterface $repository, string $phoneField, string $phone): ?int
    {
        $previous = $repository->findWhere(
            where: [$phoneField => $phone, ['chatwoot_conversation_id', 'not null', null]],
            orderAndDirections: [new OrderAndDirection('id', 'desc')],
        )->first();

        return $previous?->getChatwootConversationId();
    }

    /**
     * The same text already posted in the conversation less than 10 minutes ago, by PICHA or by a script of Anicka
     * during the switch-over, is not posted twice. Labels like « [Anicka] » are ignored in the comparison.
     *
     * @throws ChatwootUnavailableException
     */
    private function findRecentCopy(int $chatwootConversationId, string $content): ?int
    {
        $wanted = self::normalizeForComparison($content);
        $since = Carbon::now()->subSeconds(self::RECENT_COPY_WINDOW_SECONDS)->getTimestamp();

        foreach ($this->client->recentMessages($chatwootConversationId) as $message) {
            if (! $message['private']
                && $message['created_at'] >= $since
                && self::normalizeForComparison($message['content']) === $wanted) {
                return $message['id'];
            }
        }

        return null;
    }

    private static function normalizeForComparison(string $content): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim((string) preg_replace('/^\[[^\]]{1,40}\]\s*/u', '', trim($content))));
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
