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
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsCampaignDeliveryService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Domain\Sms\SmsLineService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use HiEvents\Services\Domain\Sms\SmsUnattributedSmsService;
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

            $candidates = $this->lineService->candidates($settings);
            $target = $candidates->count() > 1 ? $this->attributeInbound($settings, $candidates, $sms) : $settings;
            if ($target === null) {
                return;
            }

            $message = $this->inboundService->recordInbound($target, $sms, lineSettingsId: $settings->getId());

            if ($message !== null) {
                $this->alertService->notifyInbound($target, $message);
                $this->assistantService->queueDraftIfEligible($target, $message);
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

        $members = $this->lineService->candidates($settings);
        $organizerIds = $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->values()->all();

        $this->campaignDeliveryService->applyProviderStatus(
            organizerIds: $organizerIds,
            providerMessageId: $messageId,
            status: SmsCampaignRecipientStatus::from($status->value),
            failureReason: $data['error_message'] ?? null,
            timestamp: $data['timestamp'] ?? null,
        );

        $this->inboundService->recordOutboundEvent($this->outboundOwner($settings, $members, $messageId), new OutboundSmsEventDTO(
            status: $status,
            providerMessageId: $messageId,
            contact: $data['contact'],
            content: isset($data['content']) ? (string) $data['content'] : null,
            requestId: isset($data['request_id']) && $data['request_id'] !== '' ? (string) $data['request_id'] : null,
            errorMessage: $data['error_message'] ?? null,
            timestamp: $data['timestamp'] ?? null,
        ), organizerIds: $organizerIds, lineSettingsId: $settings->getId());
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
     * The organizer a message unknown to PICHA is stored for: the organizer of the campaign that sent it, otherwise the line owner.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $members
     */
    private function outboundOwner(OrganizerSmsSettingDomainObject $line, Collection $members, string $providerMessageId): OrganizerSmsSettingDomainObject
    {
        if ($members->count() <= 1) {
            return $line;
        }

        $organizerId = $this->campaignDeliveryService->findCampaignOrganizerId(
            $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->values()->all(),
            $providerMessageId,
        );

        return $members->first(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId() === $organizerId) ?? $line;
    }

    private function ownerMatches(OrganizerSmsSettingDomainObject $settings, mixed $owner): bool
    {
        if ($settings->getFromNumber() === null || ! is_string($owner)) {
            return true;
        }

        return SmsPhoneNormalizer::normalize($owner) === $settings->getFromNumber();
    }
}
