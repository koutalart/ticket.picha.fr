<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\Sms\SmsInboundAlertMail;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsInboundAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_MESSAGES = 5;

    public int $tries = 3;

    public function __construct(
        public readonly int $conversationId,
    ) {
        $this->afterCommit = true;
    }

    public function handle(
        SmsConversationRepositoryInterface $conversationRepository,
        SmsMessageRepositoryInterface $messageRepository,
        OrganizerSmsSettingRepositoryInterface $settingsRepository,
        OrganizerRepositoryInterface $organizerRepository,
        Mailer $mailer,
    ): void {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $conversationRepository->findFirstWhere(['id' => $this->conversationId]);
        if ($conversation === null || $conversation->getUnreadCount() <= 0) {
            return;
        }

        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $conversation->getOrganizerId(),
        ]);
        if ($settings === null || ! $settings->getNotifyOnInboundSms()) {
            return;
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $organizerRepository->findFirstWhere(['id' => $conversation->getOrganizerId()]);
        if ($organizer === null || $organizer->getEmail() === '') {
            return;
        }

        $messages = $messageRepository->findLatestInbound(
            $conversation->getId(),
            min($conversation->getUnreadCount(), self::MAX_MESSAGES),
        );
        if ($messages->isEmpty()) {
            return;
        }

        $mailer
            ->to($organizer->getEmail(), $organizer->getName())
            ->locale(CustomerLocale::get())
            ->send(new SmsInboundAlertMail($organizer, $conversation, $messages));
    }
}
