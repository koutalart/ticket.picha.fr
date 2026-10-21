<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsWebhookUnauthorizedException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\HttpSmsWebhookDTO;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Domain\Sms\HttpSmsWebhookSignatureVerifier;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsInboundService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

class HandleHttpSmsWebhookHandler
{
    public const EVENT_RECEIVED = 'message.phone.received';

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
        private readonly SmsAssistantService $assistantService,
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
        if ($type === self::EVENT_RECEIVED) {
            $messageId = $data['message_id'] ?? $data['id'] ?? null;
            if (! is_string($messageId) || ! is_string($data['contact'] ?? null)) {
                return;
            }

            $message = $this->inboundService->recordInbound($settings, new InboundSmsDTO(
                providerMessageId: $messageId,
                contact: $data['contact'],
                content: (string) ($data['content'] ?? ''),
                timestamp: $data['timestamp'] ?? null,
            ));

            if ($message !== null) {
                $this->assistantService->queueDraftIfEligible($settings, $message);
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

        $this->inboundService->recordOutboundEvent($settings, new OutboundSmsEventDTO(
            status: $status,
            providerMessageId: $messageId,
            contact: $data['contact'],
            content: isset($data['content']) ? (string) $data['content'] : null,
            requestId: isset($data['request_id']) && $data['request_id'] !== '' ? (string) $data['request_id'] : null,
            errorMessage: $data['error_message'] ?? null,
            timestamp: $data['timestamp'] ?? null,
        ));
    }

    private function ownerMatches(OrganizerSmsSettingDomainObject $settings, mixed $owner): bool
    {
        if ($settings->getFromNumber() === null || ! is_string($owner)) {
            return true;
        }

        return SmsPhoneNormalizer::normalize($owner) === $settings->getFromNumber();
    }
}
