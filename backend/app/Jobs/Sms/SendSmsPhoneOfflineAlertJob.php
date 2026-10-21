<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\Status\SmsPhoneStatus;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\Sms\SmsPhoneOfflineAlertMail;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsPhoneOfflineAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $settingsId,
    ) {
        $this->afterCommit = true;
    }

    public function handle(
        OrganizerSmsSettingRepositoryInterface $settingsRepository,
        OrganizerRepositoryInterface $organizerRepository,
        Mailer $mailer,
    ): void {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $settingsRepository->findFirstWhere(['id' => $this->settingsId]);
        if ($settings === null || $settings->getPhoneStatus() !== SmsPhoneStatus::OFFLINE->name) {
            return;
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirstWhere(['id' => $settings->getOrganizerId()]);
        if ($organizer === null || $organizer->getEmail() === '') {
            return;
        }

        $mailer
            ->to($organizer->getEmail(), $organizer->getName())
            ->locale(CustomerLocale::get())
            ->send(new SmsPhoneOfflineAlertMail($organizer, $settings));
    }
}
