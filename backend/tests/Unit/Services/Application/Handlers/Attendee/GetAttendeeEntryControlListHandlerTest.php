<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\BoxOfficeSaleRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\GetAttendeeEntryControlListHandler;
use Mockery;
use Tests\TestCase;

class GetAttendeeEntryControlListHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_loads_attendees_sorted_by_name_with_questions_and_phones(): void
    {
        $attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $questionRepository = Mockery::mock(QuestionRepositoryInterface::class);
        $boxOfficeSaleRepository = Mockery::mock(BoxOfficeSaleRepositoryInterface::class);
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);

        $eventRepository->shouldReceive('findById')->once()->with(4)
            ->andReturn((new EventDomainObject)->setId(4)->setTimezone('Indian/Mayotte'));

        $attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $attendeeRepository->shouldReceive('findByEventIdForExport')->once()->with(4)->andReturn(collect([
            (new AttendeeDomainObject)->setId(1)->setFirstName('Zaïna')->setLastName('Madi'),
            (new AttendeeDomainObject)->setId(2)->setFirstName('Ali')->setLastName('ahmed'),
            (new AttendeeDomainObject)->setId(3)->setFirstName('Ben')->setLastName('Ahmed'),
        ]));

        $questionRepository->shouldReceive('findWhere')->once()
            ->with([QuestionDomainObject::EVENT_ID => 4, QuestionDomainObject::BELONGS_TO => QuestionBelongsTo::ORDER->name])
            ->andReturn(collect([
                (new QuestionDomainObject)->setId(9)->setOrder(2),
                (new QuestionDomainObject)->setId(8)->setOrder(1),
            ]));
        $questionRepository->shouldReceive('findWhere')->once()
            ->with([QuestionDomainObject::EVENT_ID => 4, QuestionDomainObject::BELONGS_TO => QuestionBelongsTo::PRODUCT->name])
            ->andReturn(collect());

        $boxOfficeSaleRepository->shouldReceive('findPhonesByEventId')->once()->with(4)->andReturn([12 => '0639112233']);

        $handler = new GetAttendeeEntryControlListHandler(
            $attendeeRepository,
            $questionRepository,
            $boxOfficeSaleRepository,
            $eventRepository,
        );

        $result = $handler->handle(4);

        $this->assertSame([2, 3, 1], $result->attendees->map(fn ($attendee) => $attendee->getId())->all());
        $this->assertSame([8, 9], $result->orderQuestions->map(fn ($question) => $question->getId())->all());
        $this->assertTrue($result->productQuestions->isEmpty());
        $this->assertSame([12 => '0639112233'], $result->boxOfficePhonesByOrderId);
        $this->assertSame('Indian/Mayotte', $result->timezone);
    }
}
