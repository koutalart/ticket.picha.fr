<?php

namespace Tests\Unit\DomainObjects;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventLifecycleStatus;
use Tests\TestCase;

class EventEndedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_event_with_past_end_date_is_ended(): void
    {
        $event = $this->event('2026-11-03 18:00:00', '2026-11-04 02:00:00');

        $this->assertTrue($event->isEventInPast());
        $this->assertSame(EventLifecycleStatus::ENDED->name, $event->getLifecycleStatus());
    }

    public function test_event_with_future_end_date_is_not_ended_even_long_after_start(): void
    {
        $event = $this->event('2026-11-01 08:00:00', '2026-11-05 20:00:00');

        $this->assertFalse($event->isEventInPast());
        $this->assertSame(EventLifecycleStatus::ONGOING->name, $event->getLifecycleStatus());
    }

    public function test_event_without_end_date_is_ongoing_during_twelve_hours_after_start(): void
    {
        $event = $this->event('2026-11-04 00:30:00');

        $this->assertFalse($event->isEventInPast());
        $this->assertTrue($event->isEventOngoing());
        $this->assertSame(EventLifecycleStatus::ONGOING->name, $event->getLifecycleStatus());
    }

    public function test_event_without_end_date_is_ended_twelve_hours_after_start(): void
    {
        $event = $this->event('2026-11-03 23:59:00');

        $this->assertTrue($event->isEventInPast());
        $this->assertFalse($event->isEventOngoing());
        $this->assertSame(EventLifecycleStatus::ENDED->name, $event->getLifecycleStatus());
        $this->assertSame('2026-11-04 11:59:00', $event->getEffectiveEndDate()->setTimezone('UTC')->toDateTimeString());
    }

    public function test_upcoming_event_without_end_date_is_not_ended(): void
    {
        $event = $this->event('2026-11-10 20:00:00');

        $this->assertFalse($event->isEventInPast());
        $this->assertSame(EventLifecycleStatus::UPCOMING->name, $event->getLifecycleStatus());
    }

    public function test_event_without_any_date_is_never_ended(): void
    {
        $event = $this->event(null);

        $this->assertNull($event->getEffectiveEndDate());
        $this->assertFalse($event->isEventInPast());
        $this->assertSame(EventLifecycleStatus::ONGOING->name, $event->getLifecycleStatus());
    }

    private function event(?string $startDate, ?string $endDate = null): EventDomainObject
    {
        return (new EventDomainObject)
            ->setTimezone('Indian/Mayotte')
            ->setStartDate($startDate)
            ->setEndDate($endDate);
    }
}
