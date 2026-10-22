<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Jobs\Sms\SendAttendanceAccessCodeSmsJob;
use HiEvents\Mail\Attendee\AttendanceAccessCodeMail;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Sends the code only to the contact already recorded on the registration.
 */
class AttendanceAccessNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function send(EventDomainObject $event, AttendanceAccessContactDTO $contact, string $code): void
    {
        if ($contact->isEmail()) {
            $this->mailer
                ->to($contact->value)
                ->locale(CustomerLocale::get())
                ->queue(new AttendanceAccessCodeMail(
                    eventTitle: $event->getTitle(),
                    code: $code,
                    validityMinutes: (int) (AttendanceAccessCodeService::TTL_SECONDS / 60),
                ));

            return;
        }

        $this->dispatcher->dispatch(new SendAttendanceAccessCodeSmsJob(
            organizerId: $event->getOrganizerId(),
            phone: $contact->value,
            content: __(':code is your code to manage your attendance at :event. Valid for :minutes minutes. Do not share it.', [
                'code' => $code,
                'event' => $event->getTitle(),
                'minutes' => (int) (AttendanceAccessCodeService::TTL_SECONDS / 60),
            ], CustomerLocale::get()),
        ));
    }
}
