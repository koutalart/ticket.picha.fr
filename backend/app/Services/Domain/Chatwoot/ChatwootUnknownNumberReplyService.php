<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Chatwoot;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsUnattributedMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A reply typed in Chatwoot to a number without a PICHA conversation leaves by SMS from the line that received its SMS.
 * It is not filed with any organizer: the number stays with the platform until an admin assigns its SMS.
 */
class ChatwootUnknownNumberReplyService
{
    public const REQUEST_ID_PREFIX = 'picha-chatwoot-';

    private const SENT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly SmsUnattributedMessageRepositoryInterface $unattributedRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsConversationService $conversationService,
        private readonly HttpSmsClient $httpSmsClient,
        private readonly ChatwootSmsMirrorService $mirrorService,
        private readonly Cache $cache,
    ) {}

    /**
     * @return bool false when the Chatwoot conversation is not the one of a number without conversation
     *
     * @throws SmsRecipientOptedOutException
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsGatewayException
     */
    public function reply(int $chatwootConversationId, int $chatwootMessageId, string $content): bool
    {
        /** @var SmsUnattributedMessageDomainObject|null $last */
        $last = $this->unattributedRepository->findWhere(
            where: [SmsUnattributedMessageDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId],
            orderAndDirections: [new OrderAndDirection(SmsUnattributedMessageDomainObjectAbstract::ID, 'desc')],
        )->first();

        if ($last === null) {
            return false;
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ID => $last->getLineSettingsId(),
        ]);

        if ($line === null || ! $line->getApiKey() || ! $line->getFromNumber()) {
            throw new SmsGatewayNotConfiguredException(__('The SMS line that received this number is not configured any more'));
        }

        if ($this->conversationService->isOptedOut($line->getOrganizerId(), $last->getPhone())) {
            throw new SmsRecipientOptedOutException(__('This number asked not to receive SMS any more'));
        }

        $sentKey = 'chatwoot:unknown-number-reply:'.$chatwootMessageId;
        if (! $this->cache->add($sentKey, true, self::SENT_TTL_SECONDS)) {
            return true;
        }

        try {
            $this->httpSmsClient->sendMessage(
                apiKey: (string) $line->getApiKey(),
                from: (string) $line->getFromNumber(),
                to: $last->getPhone(),
                content: $content,
                requestId: self::REQUEST_ID_PREFIX.$chatwootConversationId.'-'.$chatwootMessageId,
            );
        } catch (SmsGatewayException $exception) {
            $this->cache->forget($sentKey);

            throw $exception;
        }

        return true;
    }

    /**
     * Delivery events of these replies concern no organizer: they are kept out of the organizer inboxes, and a failure
     * is noted in the Chatwoot conversation.
     *
     * @return bool true when the event belongs to a reply sent from Chatwoot to a number without conversation
     */
    public function handleDeliveryEvent(?string $requestId, SmsMessageStatus $status, ?string $error, ?string $content): bool
    {
        if ($requestId === null || ! str_starts_with($requestId, self::REQUEST_ID_PREFIX)) {
            return false;
        }

        if (! in_array($status, [SmsMessageStatus::FAILED, SmsMessageStatus::EXPIRED], true)) {
            return true;
        }

        $chatwootConversationId = (int) explode('-', substr($requestId, strlen(self::REQUEST_ID_PREFIX)))[0];
        if ($chatwootConversationId > 0) {
            $this->mirrorService->postNote(
                $chatwootConversationId,
                __('SMS not delivered: :content', ['content' => mb_substr((string) $content, 0, 80)])
                    .($error ? ' ('.$error.')' : ''),
            );
        }

        return true;
    }
}
