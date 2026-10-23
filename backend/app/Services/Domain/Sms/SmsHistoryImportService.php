<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\SmsHistoryImportStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\OutboundSmsEventDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

class SmsHistoryImportService
{
    public const PAGE_SIZE = 20;

    public const MAX_THREADS = 5000;

    public const MAX_MESSAGES_PER_THREAD = 500;

    private const STALE_AFTER_MINUTES = 60;

    private const INBOUND_TYPE = 'mobile-originated';

    private const OUTBOUND_STATUSES = [
        'pending' => SmsMessageStatus::PENDING,
        'scheduled' => SmsMessageStatus::PENDING,
        'sending' => SmsMessageStatus::PENDING,
        'sent' => SmsMessageStatus::SENT,
        'delivered' => SmsMessageStatus::DELIVERED,
        'failed' => SmsMessageStatus::FAILED,
        'expired' => SmsMessageStatus::EXPIRED,
    ];

    public function __construct(
        private readonly HttpSmsClient $httpSmsClient,
        private readonly SmsInboundService $inboundService,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsLineService $lineService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws SmsGatewayNotConfiguredException
     * @throws ResourceConflictException
     */
    public function markStarted(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        $this->assertConfigured($settings);
        $this->assertNotShared($settings);

        if ($this->isRunning($settings)) {
            throw new ResourceConflictException(__('The SMS history is already being imported'));
        }

        return $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_STATUS => SmsHistoryImportStatus::RUNNING->name,
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_STARTED_AT => now()->toDateTimeString(),
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_FINISHED_AT => null,
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_COUNT => 0,
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_ERROR => null,
        ]);
    }

    /**
     * Imports one page of httpSMS threads and returns the offset of the next page, or null when done.
     *
     * @throws Throwable
     */
    public function importPage(int $settingsId, int $skip): ?int
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirst($settingsId);
        if ($settings === null || $settings->getHistoryImportStatus() !== SmsHistoryImportStatus::RUNNING->name) {
            return null;
        }

        try {
            $this->assertConfigured($settings);
            $this->assertNotShared($settings);

            $threads = $this->httpSmsClient->listMessageThreads(
                (string) $settings->getApiKey(),
                (string) $settings->getFromNumber(),
                $skip,
                self::PAGE_SIZE,
            );

            $imported = 0;
            foreach ($threads as $thread) {
                $contact = $thread['contact'] ?? null;
                if (is_string($contact) && $contact !== '') {
                    $imported += $this->importConversation($settings, $contact);
                }
            }
        } catch (Throwable $exception) {
            $this->markFailed($settingsId, $exception);

            throw $exception;
        }

        if ($imported > 0) {
            $this->settingsRepository->increment($settingsId, OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_COUNT, $imported);
        }

        $nextSkip = $skip + self::PAGE_SIZE;
        if (count($threads) < self::PAGE_SIZE || $nextSkip >= self::MAX_THREADS) {
            $this->settingsRepository->updateFromArray($settingsId, [
                OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_STATUS => SmsHistoryImportStatus::COMPLETED->name,
                OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_FINISHED_AT => now()->toDateTimeString(),
            ]);

            return null;
        }

        return $nextSkip;
    }

    public function markFailed(int $settingsId, Throwable $exception): void
    {
        $message = $exception instanceof SmsGatewayException
            || $exception instanceof SmsGatewayNotConfiguredException
            || $exception instanceof ResourceConflictException
            ? $exception->getMessage()
            : __('Unexpected error');

        $this->settingsRepository->updateFromArray($settingsId, [
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_STATUS => SmsHistoryImportStatus::FAILED->name,
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_FINISHED_AT => now()->toDateTimeString(),
            OrganizerSmsSettingDomainObjectAbstract::HISTORY_IMPORT_ERROR => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @throws SmsGatewayException
     * @throws Throwable
     */
    private function importConversation(OrganizerSmsSettingDomainObject $settings, string $contact): int
    {
        $messages = [];

        for ($skip = 0; $skip < self::MAX_MESSAGES_PER_THREAD; $skip += self::PAGE_SIZE) {
            $page = $this->httpSmsClient->listMessages(
                (string) $settings->getApiKey(),
                (string) $settings->getFromNumber(),
                $contact,
                $skip,
                self::PAGE_SIZE,
            );

            array_push($messages, ...$page);

            if (count($page) < self::PAGE_SIZE) {
                break;
            }
        }

        usort($messages, fn (array $a, array $b) => $this->sortKey($a) <=> $this->sortKey($b));

        $imported = 0;
        foreach ($messages as $message) {
            if ($this->recordMessage($settings, $message)) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * @throws Throwable
     */
    private function recordMessage(OrganizerSmsSettingDomainObject $settings, array $message): bool
    {
        $id = $message['id'] ?? null;
        $contact = $message['contact'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($contact)) {
            return false;
        }

        $isInbound = ($message['type'] ?? null) === self::INBOUND_TYPE
            || strtolower((string) ($message['status'] ?? '')) === 'received';
        $requestId = is_string($message['request_id'] ?? null) && $message['request_id'] !== '' ? $message['request_id'] : null;
        $known = $this->isKnown($settings->getOrganizerId(), $id, $isInbound ? null : $requestId);

        if ($known && $isInbound) {
            return false;
        }

        try {
            if ($isInbound) {
                return $this->inboundService->recordInbound($settings, new InboundSmsDTO(
                    providerMessageId: $id,
                    contact: $contact,
                    content: (string) ($message['content'] ?? ''),
                    timestamp: $this->timestamp($message, 'received_at'),
                ), fromHistory: true) !== null;
            }

            $status = self::OUTBOUND_STATUSES[strtolower((string) ($message['status'] ?? ''))] ?? SmsMessageStatus::PENDING;
            $failureReason = $message['failure_reason'] ?? null;

            $this->inboundService->recordOutboundEvent($settings, new OutboundSmsEventDTO(
                status: $status,
                providerMessageId: $id,
                contact: $contact,
                content: (string) ($message['content'] ?? ''),
                requestId: $requestId,
                errorMessage: is_string($failureReason) && $failureReason !== '' ? $failureReason : null,
                timestamp: $this->timestamp($message, 'sent_at'),
            ), fromHistory: true);

            return ! $known;
        } catch (InvalidSmsPhoneNumberException) {
            return false;
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('httpSMS history import skipped a message recorded concurrently', [
                'organizer_id' => $settings->getOrganizerId(),
            ]);

            return false;
        }
    }

    private function isKnown(int $organizerId, string $providerMessageId, ?string $requestId): bool
    {
        $byProviderId = $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
        ]);

        if ($byProviderId !== null) {
            return true;
        }

        if ($requestId === null || ! str_starts_with($requestId, SmsSendService::REQUEST_ID_PREFIX)) {
            return false;
        }

        return $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsMessageDomainObjectAbstract::REQUEST_ID => $requestId,
        ]) !== null;
    }

    private function timestamp(array $message, string $preferredField): ?string
    {
        foreach ([$preferredField, 'order_timestamp', 'created_at'] as $field) {
            if (is_string($message[$field] ?? null) && $message[$field] !== '') {
                return $message[$field];
            }
        }

        return null;
    }

    private function sortKey(array $message): int
    {
        $timestamp = $this->timestamp($message, 'order_timestamp');

        try {
            return $timestamp === null ? 0 : (int) Carbon::parse($timestamp)->getPreciseTimestamp(3);
        } catch (Throwable) {
            return 0;
        }
    }

    private function isRunning(OrganizerSmsSettingDomainObject $settings): bool
    {
        if ($settings->getHistoryImportStatus() !== SmsHistoryImportStatus::RUNNING->name) {
            return false;
        }

        $startedAt = $settings->getHistoryImportStartedAt();

        return $startedAt !== null
            && Carbon::parse($startedAt)->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * @throws SmsGatewayNotConfiguredException
     */
    private function assertConfigured(OrganizerSmsSettingDomainObject $settings): void
    {
        if (! $settings->getApiKey() || ! $settings->getFromNumber()) {
            throw new SmsGatewayNotConfiguredException(__('Add your PICHA SMS API key and sending number first'));
        }
    }

    /**
     * The history of a shared line mixes the conversations of several organizers, it cannot be given to one of them.
     *
     * @throws ResourceConflictException
     */
    private function assertNotShared(OrganizerSmsSettingDomainObject $settings): void
    {
        if ($this->lineService->isShared($settings)) {
            throw new ResourceConflictException(__('The SMS history cannot be imported on a line shared by several organizers'));
        }
    }
}
