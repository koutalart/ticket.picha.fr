<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Contracts\Mail\Mailer;

class SendAttendeeTicketService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly MailBuilderService $mailBuilderService,
    ) {}

    /**
     * @param  AttendeeDomainObject[]  $tickets  Tickets attached to the same e-mail, all for the attendee's address; defaults to the attendee's own ticket
     */
    public function send(
        OrderDomainObject $order,
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject $organizer,
        array $tickets = [],
    ): void {
        $mail = $this->mailBuilderService->buildAttendeeTicketMail(
            $attendee,
            $order,
            $event,
            $eventSettings,
            $organizer,
            $tickets,
        );

        if (KioskSentinelEmail::isKioskSentinelEmail($attendee->getEmail())) {
            return;
        }

        $this->mailer
            ->to($attendee->getEmail())
            ->locale(CustomerLocale::get())
            ->send($mail);
    }
}
