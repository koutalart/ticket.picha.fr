<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeEntryControlListDTO;
use Illuminate\Support\Collection;

class GetAttendeeEntryControlListHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly BoxOfficeSaleRepositoryInterface $boxOfficeSaleRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function handle(int $eventId): AttendeeEntryControlListDTO
    {
        $event = $this->eventRepository->findById($eventId);

        $attendees = $this->attendeeRepository
            ->loadRelation(QuestionAndAnswerViewDomainObject::class)
            ->loadRelation(new Relationship(
                domainObject: AttendeeCheckInDomainObject::class,
                name: 'check_ins',
            ))
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: ProductPriceDomainObject::class,
                    ),
                ],
                name: 'product'
            ))
            ->loadRelation(new Relationship(
                domainObject: OrderDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: QuestionAndAnswerViewDomainObject::class
                    ),
                ],
                name: 'order'
            ))
            ->findByEventIdForExport($eventId);

        return new AttendeeEntryControlListDTO(
            attendees: $this->sortByName($attendees),
            orderQuestions: $this->findQuestions($eventId, QuestionBelongsTo::ORDER),
            productQuestions: $this->findQuestions($eventId, QuestionBelongsTo::PRODUCT),
            boxOfficePhonesByOrderId: $this->boxOfficeSaleRepository->findPhonesByEventId($eventId),
            timezone: $event->getTimezone() ?? config('app.timezone', 'UTC'),
        );
    }

    private function findQuestions(int $eventId, QuestionBelongsTo $belongsTo): Collection
    {
        return $this->questionRepository
            ->findWhere([
                QuestionDomainObject::EVENT_ID => $eventId,
                QuestionDomainObject::BELONGS_TO => $belongsTo->name,
            ])
            ->sortBy(fn (QuestionDomainObject $question) => [$question->getOrder() ?? PHP_INT_MAX, $question->getId()])
            ->values();
    }

    private function sortByName(Collection $attendees): Collection
    {
        return $attendees
            ->sortBy(fn (AttendeeDomainObject $attendee) => [
                mb_strtolower(trim((string) $attendee->getLastName())),
                mb_strtolower(trim((string) $attendee->getFirstName())),
                $attendee->getId(),
            ])
            ->values();
    }
}
