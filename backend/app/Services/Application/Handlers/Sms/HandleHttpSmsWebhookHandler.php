<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\SmsCampaignRecipientStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\DecideLiveChatRefundHandler;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Domain\Chatwoot\ChatwootUnknownNumberReplyService;
use HiEvents\Services\Domain\LiveChat\Question\LiveChatQuestionService;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use HiEvents\Services\Domain\Sms\SmsFileStatusService;
use HiEvents\Services\Domain\Sms\SmsFileSyncService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use HiEvents\Services\Domain\Sms\SmsUnattributedOutboundService;
use HiEvents\Services\Domain\Sms\SmsUnattributedSmsService;
use HiEvents\Services\Domain\Sms\Team\SmsTeamInboundService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

class HandleHttpSmsWebhookHandler
{
    public const EVENT_RECEIVED = 'message.phone.received';

    private const PHONE_STATUS_EVENTS = [
        'phone.heartbeat.offline' => SmsPhoneStatus::OFFLINE,
        'phone.heartbeat.online' => SmsPhoneStatus::ONLINE,
    ];

    private const PHONE_ACTIVITY_EVENTS = [
        self::EVENT_RECEIVED,
        'message.phone.sent',
        'message.phone.delivered',
    ];

    private const OUTBOUND_EVENTS = [
        'message.phone.sent' => SmsMessageStatus::SENT,
        'message.phone.delivered' => SmsMessageStatus::DELIVERED,
        'message.send.failed' => SmsMessageStatus::FAILED,
        'message.send.expired' => SmsMessageStatus::EXPIRED,
    ];

    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly HttpSmsWebhookSignatureVerifier $signatureVerifier,
        private readonly SmsInboundService $inboundService,
        private readonly SmsCampaignDeliveryService $campaignDeliveryService,
        private readonly SmsAlertService $alertService,
        private readonly SmsAssistantService $assistantService,
        private readonly SmsLineService $lineService,
        private readonly SmsUnattributedSmsService $unattributedService,
        private readonly LoggerInterface $logger,
        private readonly SmsTeamInboundService $teamInboundService,
        private readonly DecideLiveChatRefundHandler $refundDecisionHandler,
        private readonly LiveChatQuestionService $questionService,
        private readonly SmsFileSyncService $fileSyncService,
        private readonly SmsLineKeywordService $keywordService,
        private readonly ChatwootUnknownNumberReplyService $chatwootUnknownNumberReplyService,
        private readonly SmsUnattributedOutboundService $unattributedOutboundService,
    ) {}

    /**
     * @throws SmsWebhookUnauthorizedException
     * @throws Throwable
     */
    public function handle(HttpSmsWebhookDTO $dto): void
    {
        $settings = $this->settingsService->findByWebhookToken($dto->webhookToken);
        if ($settings === null) {
            throw new SmsWebhookUnauthorizedException('Unknown webhook');
        }

        if ($settings->getSharedLineSettingsId() !== null) {
            $this->logger->warning('httpSMS webhook rejected: the organizer uses a shared line, only the line webhook is accepted', [
                'organizer_id' => $settings->getOrganizerId(),
            ]);

            throw new SmsWebhookUnauthorizedException('Webhook of a shared line member');
        }

        $this->signatureVerifier->verify($dto->bearerToken, (string) $settings->getWebhookSigningKey());

        $event = json_decode($dto->payload, true);
        if (! is_array($event) || ! is_array($event['data'] ?? null)) {
            $this->logger->warning('httpSMS webhook ignored: malformed payload', ['organizer_id' => $settings->getOrganizerId()]);

            return;
        }

        $type = $dto->eventType ?: ($event['type'] ?? null);
        $data = $event['data'];

        $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::LAST_WEBHOOK_RECEIVED_AT => now()->toDateTimeString(),
        ]);

        if (! $this->ownerMatches($settings, $data['owner'] ?? null)) {
            $this->logger->warning('httpSMS webhook ignored: owner does not match sending number', [
                'organizer_id' => $settings->getOrganizerId(),
            ]);

            return;
        }

        try {
            $this->dispatch($settings, $type, $data);
        } catch (InvalidSmsPhoneNumberException) {
            $this->logger->warning('httpSMS webhook ignored: invalid contact number', [
                'organizer_id' => $settings->getOrganizerId(),
                'type' => $type,
            ]);
        }
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    private function dispatch(OrganizerSmsSettingDomainObject $settings, ?string $type, array $data): void
    {
        $phoneStatus = self::PHONE_STATUS_EVENTS[$type] ?? null;
        if ($phoneStatus !== null) {
            $this->alertService->recordPhoneStatus(
                $settings,
                $phoneStatus,
                is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
            );

            return;
        }

        if (in_array($type, self::PHONE_ACTIVITY_EVENTS, true)) {
            $settings = $this->alertService->markPhoneActive($settings);
        }

        if ($type === self::EVENT_RECEIVED) {
            $messageId = $data['message_id'] ?? $data['id'] ?? null;
            if (! is_string($messageId) || ! is_string($data['contact'] ?? null)) {
                return;
            }

            $sms = new InboundSmsDTO(
                providerMessageId: $messageId,
                contact: $data['contact'],
                content: (string) ($data['content'] ?? ''),
                timestamp: $data['timestamp'] ?? null,
            );

            if ($this->inboundService->isRecentDuplicate($settings->getId(), $sms)) {
                $this->logger->info('httpSMS inbound SMS ignored: same text from the same number a moment ago', [
                    'line_settings_id' => $settings->getId(),
                    'provider_message_id' => $sms->providerMessageId,
                ]);

                return;
            }

            $candidates = $this->lineService->candidates($settings);

            $isInternalPhone = $this->lineService->isInternalPhone($sms->contact);
            $teamRoute = $isInternalPhone ? null : $this->teamInboundService->route($candidates, $sms->contact);
            if ($teamRoute !== null) {
                $message = $this->inboundService->recordInbound($teamRoute->settings, $sms, lineSettingsId: $settings->getId());

                if ($message !== null) {
                    if ($this->refundDecisionHandler->handleTeamSms($teamRoute->settings, $message, $sms->contact)) {
                        return;
                    }

                    // An answer to a chat question goes to the visitor: never to the customer assistant nor the organizer assistant.
                    if ($this->questionService->handleTeamSms($teamRoute->settings, $message, $sms->contact)) {
                        return;
                    }

                    if (! $this->teamInboundService->organizerAssistantActiveFor($teamRoute->settings)) {
                        $this->alertService->notifyInbound($teamRoute->settings, $message);
                    }
                    $this->teamInboundService->afterInbound($teamRoute, $message);
                }

                return;
            }

            if ($this->teamInboundService->isSupervisor($sms->contact) && $this->handleSupervisorAnswer($settings, $candidates, $sms)) {
                return;
            }

            if ($this->handleKeyword($settings, $candidates, $sms)) {
                return;
            }

            $target = $candidates->count() > 1 || $isInternalPhone ? $this->attributeInbound($settings, $candidates, $sms) : $settings;
            if ($target === null) {
                return;
            }

            $message = $this->inboundService->recordInbound($target, $sms, lineSettingsId: $settings->getId());

            if ($message !== null) {
                $this->alertService->notifyInbound($target, $message);
                if (! $this->teamInboundService->isSupervisor($sms->contact)) {
                    $this->assistantService->queueDraftIfEligible($target, $message);
                }
            }

            return;
        }

        $status = self::OUTBOUND_EVENTS[$type] ?? null;
        if ($status === null) {
            return;
        }

        $messageId = $data['id'] ?? $data['message_id'] ?? null;
        if (! is_string($messageId) || ! is_string($data['contact'] ?? null)) {
            return;
        }

        $requestId = isset($data['request_id']) && is_scalar($data['request_id']) && $data['request_id'] !== '' ? (string) $data['request_id'] : null;

        if ($this->chatwootUnknownNumberReplyService->handleDeliveryEvent(
            $requestId,
            $status,
            isset($data['error_message']) ? (string) $data['error_message'] : null,
            isset($data['content']) ? (string) $data['content'] : null,
        )) {
            return;
        }

        $members = $this->lineService->candidates($settings);
        $organizerIds = $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->values()->all();

        $this->campaignDeliveryService->applyProviderStatus(
            organizerIds: $organizerIds,
            providerMessageId: $messageId,
            status: SmsCampaignRecipientStatus::from($status->value),
            failureReason: $data['error_message'] ?? null,
            timestamp: $data['timestamp'] ?? null,
            requestId: $requestId,
        );

        $owner = $this->outboundOwner($settings, $members, $messageId, $requestId);
        if ($owner === null) {
            $this->unattributedOutboundService->record(
                line: $settings,
                providerMessageId: $messageId,
                contact: $data['contact'],
                content: isset($data['content']) ? (string) $data['content'] : null,
                requestId: $requestId,
                status: $status,
                errorMessage: isset($data['error_message']) ? (string) $data['error_message'] : null,
            );

            return;
        }

        if (! in_array($owner->getOrganizerId(), $organizerIds, true)) {
            $organizerIds[] = $owner->getOrganizerId();
        }

        $this->inboundService->recordOutboundEvent($owner, new OutboundSmsEventDTO(
            status: $status,
            providerMessageId: $messageId,
            contact: $data['contact'],
            content: isset($data['content']) ? (string) $data['content'] : null,
            requestId: $requestId,
            errorMessage: $data['error_message'] ?? null,
            timestamp: $data['timestamp'] ?? null,
        ), organizerIds: $organizerIds, lineSettingsId: $settings->getId());
    }

    /**
     * « Q12 … » from a platform supervisor answers the chat question of an organizer of the line.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $candidates
     *
     * @throws Throwable
     */
    private function handleSupervisorAnswer(OrganizerSmsSettingDomainObject $line, Collection $candidates, InboundSmsDTO $sms): bool
    {
        $question = $this->questionService->findForSupervisorAnswer(
            $candidates->map(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId())->values()->all(),
            $sms->content,
        );
        if ($question === null) {
            return false;
        }

        $owner = $candidates->first(fn (OrganizerSmsSettingDomainObject $candidate) => $candidate->getOrganizerId() === $question->getOrganizerId());
        $message = $this->inboundService->recordInbound($owner, $sms, lineSettingsId: $line->getId());
        if ($message !== null) {
            $this->questionService->answerFromSupervisor($question, $sms->content, $sms->contact);
        }

        return true;
    }

    /**
     * « JUNGLE », « Jungle 2 »: an SMS whose first word is an active keyword of the line goes, whole, to the organizer
     * of the keyword, even when another organizer of the line wrote to the number more recently. The number is added to
     * its contacts and nothing is sent back: neither an automatic reply nor an assistant draft.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $candidates
     *
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    private function handleKeyword(OrganizerSmsSettingDomainObject $line, Collection $candidates, InboundSmsDTO $sms): bool
    {
        $match = $this->keywordService->match($line, $candidates, $sms->content);
        if ($match === null) {
            return false;
        }

        if ($this->unattributedService->isKnown($sms->providerMessageId)) {
            return true;
        }

        $message = $this->keywordService->recordInbound($line, $match, $sms);
        if ($message !== null) {
            $this->logger->info('httpSMS inbound SMS attributed by its SMS keyword', [
                'line_settings_id' => $line->getId(),
                'organizer_id' => $match->settings->getOrganizerId(),
                'keyword' => $match->keyword,
                'sms_message_id' => $message->getId(),
            ]);
            $this->alertService->notifyInbound($match->settings, $message);
        }

        return true;
    }

    /**
     * On a line shared by several organizers, or recently left by one, the SMS goes to the organizer who wrote last to
     * the number from this line. When nobody did within the attribution window, the only member of the line gets it;
     * on a line shared by several members it is kept for the platform and no organizer sees it.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $members
     *
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    private function attributeInbound(OrganizerSmsSettingDomainObject $line, Collection $members, InboundSmsDTO $sms): ?OrganizerSmsSettingDomainObject
    {
        if ($this->unattributedService->isKnown($sms->providerMessageId)) {
            return null;
        }

        $phone = SmsPhoneNormalizer::normalize($sms->contact);
        if ($phone === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        $target = $this->lineService->attributeInbound($line, $members, $phone);
        if ($target !== null) {
            return $target;
        }

        $unattributed = $this->unattributedService->record($line, $members, $sms, $phone);
        if ($unattributed !== null) {
            $this->logger->info('httpSMS inbound SMS kept unattributed on a shared line', [
                'line_settings_id' => $line->getId(),
                'sms_unattributed_message_id' => $unattributed->getId(),
                'opt_out_applied_to_all_organizers' => $unattributed->getOptOutApplied(),
            ]);
        }

        return null;
    }

    /**
     * The organizer a message unknown to PICHA is stored for: the organizer of the campaign that sent it, otherwise
     * the organizer the central queue sent it for (request_id « file-{id} », e.g. sms-masse), attached to the line when
     * it is not on it yet. A queue SMS whose organizer cannot be told is never given to the line owner (null).
     * Any other SMS (sent from the phone of the line) belongs to the line owner.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $members
     */
    private function outboundOwner(OrganizerSmsSettingDomainObject $line, Collection $members, string $providerMessageId, ?string $requestId = null): ?OrganizerSmsSettingDomainObject
    {
        if ($members->count() > 1) {
            $organizerIds = $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->values()->all();
            $organizerId = $this->campaignDeliveryService->findCampaignOrganizerId($organizerIds, $providerMessageId, $requestId);
            $campaignOwner = $members->first(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId() === $organizerId);
            if ($campaignOwner !== null) {
                return $campaignOwner;
            }
        }

        $queueId = SmsFileStatusService::queueIdFromRequestId($requestId);
        if ($queueId === null || $this->inboundService->isKnownOutbound($providerMessageId, $queueId)) {
            return $line;
        }

        $organizerId = $this->fileSyncService->findQueueOrganizerId($queueId);

        return $organizerId === null ? null : $this->lineService->queueSender($line, $members, $organizerId);
    }

    private function ownerMatches(OrganizerSmsSettingDomainObject $settings, mixed $owner): bool
    {
        if ($settings->getFromNumber() === null || ! is_string($owner)) {
            return true;
        }

        return SmsPhoneNormalizer::normalize($owner) === $settings->getFromNumber();
    }
}
