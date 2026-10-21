<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Jobs\Sms\SendSmsInboundAlertJob;
use HiEvents\Jobs\Sms\SendSmsPhoneOfflineAlertJob;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Throwable;

class SmsAlertService
{
    public const INBOUND_ALERT_WINDOW_MINUTES = 15;

    public const INBOUND_ALERT_DELAY_SECONDS = 60;

    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function notifyInbound(OrganizerSmsSettingDomainObject $settings, SmsMessageDomainObject $message): void
    {
        if (! $settings->getNotifyOnInboundSms()) {
            return;
        }

        $now = now();
        $claimed = $this->conversationRepository->claimInboundNotification(
            conversationId: $message->getSmsConversationId(),
            notifiedBefore: $now->copy()->subMinutes(self::INBOUND_ALERT_WINDOW_MINUTES)->toDateTimeString(),
            now: $now->toDateTimeString(),
        );

        if (! $claimed) {
            return;
        }

        $this->dispatcher->dispatch(
            (new SendSmsInboundAlertJob($message->getSmsConversationId()))
                ->delay($now->copy()->addSeconds(self::INBOUND_ALERT_DELAY_SECONDS))
        );
    }

    public function recordPhoneStatus(
        OrganizerSmsSettingDomainObject $settings,
        SmsPhoneStatus $status,
        ?string $timestamp = null,
    ): OrganizerSmsSettingDomainObject {
        $changedAt = $this->parseTimestamp($timestamp) ?? now()->utc();
        $previousChangedAt = $this->parseTimestamp($settings->getPhoneStatusChangedAt());

        if ($previousChangedAt !== null && $changedAt->lt($previousChangedAt)) {
            return $settings;
        }

        if ($settings->getPhoneStatus() === $status->name) {
            return $settings;
        }

        $updated = $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS => $status->name,
            OrganizerSmsSettingDomainObjectAbstract::PHONE_STATUS_CHANGED_AT => $changedAt->toDateTimeString(),
        ]);

        if ($status === SmsPhoneStatus::OFFLINE) {
            $this->dispatcher->dispatch(new SendSmsPhoneOfflineAlertJob($settings->getId()));
        }

        return $updated;
    }

    public function markPhoneActive(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        if ($settings->getPhoneStatus() !== SmsPhoneStatus::OFFLINE->name) {
            return $settings;
        }

        return $this->recordPhoneStatus($settings, SmsPhoneStatus::ONLINE);
    }

    private function parseTimestamp(?string $timestamp): ?Carbon
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
