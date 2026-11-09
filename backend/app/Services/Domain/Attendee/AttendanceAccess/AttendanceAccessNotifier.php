<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Jobs\Sms\SendAttendanceAccessCodeSmsJob;
use HiEvents\Mail\Attendee\AttendanceAccessCodeMail;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
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
        private readonly CustomerLocaleResolver $customerLocaleResolver,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    /**
     * @param  int[]  $attendeeIds  The registrations matched by the contact, used to greet the person by first name
     */
    public function send(EventDomainObject $event, AttendanceAccessContactDTO $contact, string $code, array $attendeeIds = []): void
    {
        $locale = $this->customerLocaleResolver->forEvent($event);

        if ($contact->isEmail()) {
            $this->mailer
                ->to($contact->value)
                ->locale($locale)
                ->queue(new AttendanceAccessCodeMail(
                    eventTitle: $event->getTitle(),
                    code: $code,
                    validityMinutes: (int) (AttendanceAccessCodeService::TTL_SECONDS / 60),
                    event: $event,
                    organizer: $this->organizer($event),
                    firstName: $this->firstName($contact->value, $attendeeIds),
                ));

            return;
        }

        $this->dispatcher->dispatch(new SendAttendanceAccessCodeSmsJob(
            organizerId: $event->getOrganizerId(),
            phone: $contact->value,
            content: __('Here is your code for :event: :code. Valid for :minutes minutes. Do not share it.', [
                'code' => $code,
                'event' => $event->getTitle(),
                'minutes' => (int) (AttendanceAccessCodeService::TTL_SECONDS / 60),
            ], $locale),
        ));
    }

    private function organizer(EventDomainObject $event): ?OrganizerDomainObject
    {
        if ($event->getOrganizer() !== null) {
            return $event->getOrganizer();
        }

        if ($event->getOrganizerId() === null) {
            return null;
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirst($event->getOrganizerId());

        return $organizer;
    }

    /**
     * The first name of the registration made with this e-mail, else of the order made with it.
     *
     * @param  int[]  $attendeeIds
     */
    private function firstName(string $email, array $attendeeIds): ?string
    {
        if ($attendeeIds === []) {
            return null;
        }

        $email = mb_strtolower($email);
        $attendees = $this->attendeeRepository->findWhereIn(AttendeeDomainObjectAbstract::ID, $attendeeIds);

        $attendee = $attendees->first(fn (AttendeeDomainObject $attendee) => mb_strtolower($attendee->getEmail()) === $email
            && trim($attendee->getFirstName()) !== '');

        if ($attendee !== null) {
            return trim($attendee->getFirstName());
        }

        $orderIds = $attendees
            ->map(fn (AttendeeDomainObject $attendee) => $attendee->getOrderId())
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return null;
        }

        $order = $this->orderRepository
            ->findWhereIn(OrderDomainObjectAbstract::ID, $orderIds)
            ->first(fn (OrderDomainObject $order) => mb_strtolower((string) $order->getEmail()) === $email
                && trim((string) $order->getFirstName()) !== '');

        return $order !== null ? trim((string) $order->getFirstName()) : null;
    }
}
