<?php

namespace HiEvents\Services\Domain\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use Throwable;

class AttendeeAttendanceService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface    $eventRepository,
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly AttendeeCancellationService $attendeeCancellationService,
        private readonly AttendeeAttendanceLinkGenerator $linkGenerator,
    )
    {
    }

    /**
     * @throws InvalidAttendanceCodeException
     * @throws AttendanceResponseNotAllowedException
     */
    public function getContext(int $eventId, string $attendeeShortId, ?string $code): AttendeeAttendanceContextDTO
    {
        /** @var AttendeeDomainObject|null $attendee */
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(domainObject: ProductDomainObject::class, name: 'product'))
            ->findFirstWhere([
                'event_id' => $eventId,
                'short_id' => $attendeeShortId,
            ]);

        if ($attendee === null || !$this->linkGenerator->isValidCode($attendee, $code)) {
            throw new InvalidAttendanceCodeException(__('This link is invalid or has expired.', [], CustomerLocale::get()));
        }

        /** @var EventDomainObject $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($eventId);

        if (!$event->getEventSettings()?->getAllowAttendeeAttendanceResponse()) {
            throw new AttendanceResponseNotAllowedException(
                __('The organizer has not enabled attendance confirmation for this event.', [], CustomerLocale::get())
            );
        }

        $order = $this->orderRepository->findFirstWhere([
            'id' => $attendee->getOrderId(),
            'event_id' => $eventId,
        ]);

        return new AttendeeAttendanceContextDTO(
            attendee: $attendee,
            event: $event,
            order: $order,
        );
    }

    /**
     * @throws AttendanceResponseNotAllowedException
     */
    public function confirm(AttendeeAttendanceContextDTO $context): AttendeeDomainObject
    {
        $this->assertCanRespond($context);

        $attendee = $context->attendee;

        if ($attendee->getAttendanceConfirmedAt() === null) {
            $this->attendeeRepository->updateWhere(
                attributes: ['attendance_confirmed_at' => Carbon::now()->toDateTimeString()],
                where: [
                    'id' => $attendee->getId(),
                    'status' => AttendeeStatus::ACTIVE->name,
                ],
            );
        }

        return $this->attendeeRepository->findById($attendee->getId());
    }

    /**
     * @throws AttendanceResponseNotAllowedException
     * @throws Throwable
     */
    public function cancel(AttendeeAttendanceContextDTO $context): AttendeeDomainObject
    {
        $this->assertCanRespond($context);

        $cancelled = $this->attendeeCancellationService->cancel($context->attendee, [
            'cancelled_by_attendee_at' => Carbon::now()->toDateTimeString(),
            'attendance_confirmed_at' => null,
        ]);

        if (!$cancelled) {
            throw new AttendanceResponseNotAllowedException(__('This registration has already been cancelled.', [], CustomerLocale::get()));
        }

        return $this->attendeeRepository->findById($context->attendee->getId());
    }

    /**
     * @throws AttendanceResponseNotAllowedException
     */
    private function assertCanRespond(AttendeeAttendanceContextDTO $context): void
    {
        $blocker = $this->getBlockingReason($context);

        if ($blocker !== null) {
            throw new AttendanceResponseNotAllowedException($blocker);
        }
    }

    public function getBlockingReason(AttendeeAttendanceContextDTO $context): ?string
    {
        if ($context->attendee->getStatus() === AttendeeStatus::CANCELLED->name) {
            return __('This registration has already been cancelled.', [], CustomerLocale::get());
        }

        if ($context->attendee->getStatus() !== AttendeeStatus::ACTIVE->name
            || $context->order?->getStatus() !== OrderStatus::COMPLETED->name) {
            return __('This registration cannot be changed.', [], CustomerLocale::get());
        }

        if ($context->event->getStartDate() !== null && !$context->event->isEventInFuture()) {
            return __('The event has already started, this registration can no longer be changed.', [], CustomerLocale::get());
        }

        return null;
    }
}
