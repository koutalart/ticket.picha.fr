<?php

namespace Tests\Unit\Services\Application\Handlers\CaseStudy;

use HiEvents\Repository\Interfaces\CaseStudyStatsRepositoryInterface;
use HiEvents\Services\Application\Handlers\CaseStudy\GetCaseStudyStatsHandler;
use Mockery as m;
use Tests\TestCase;

class GetCaseStudyStatsHandlerTest extends TestCase
{
    public function test_builds_aggregated_stats_per_event(): void
    {
        $repository = m::mock(CaseStudyStatsRepositoryInterface::class);
        $repository->shouldReceive('getEvents')->once()->with([4])->andReturn([[
            'id' => 4,
            'title' => 'Triangle des bermudes',
            'status' => 'LIVE',
            'category' => 'MUSIC',
            'timezone' => 'Indian/Mayotte',
            'start_local' => '2026-10-11T18:00',
            'end_local' => '2026-10-12T00:30',
            'organizer' => 'Innocent Event',
            'location_details' => '{"city":"Mamoudzou","country":"YT"}',
            'first_sale_utc' => '2026-10-01 18:49:37',
            'tickets_active' => 755,
            'orders_completed' => 285,
        ]]);
        $repository->shouldReceive('getTicketsByChannelAndProduct')->once()->with(4)
            ->andReturn([['channel' => 'STRIPE', 'product' => 'Entrée simple', 'tickets' => 163]]);
        $repository->shouldReceive('getTicketsByDay')->once()->with(4, 'Indian/Mayotte')
            ->andReturn([['day' => '2026-10-02', 'channel' => 'STRIPE', 'tickets' => 111]]);
        $repository->shouldReceive('getPrices')->once()->with(4)->andReturn([]);
        $repository->shouldReceive('countCheckedInAttendees')->once()->with(4)->andReturn(0);
        $repository->shouldReceive('getCheckInsByHour')->once()->with(4, 'Indian/Mayotte')->andReturn([]);

        $result = (new GetCaseStudyStatsHandler($repository))->handle([4]);

        $this->assertCount(1, $result);
        $this->assertSame(755, $result[0]->tickets_active);
        $this->assertSame('Mamoudzou', $result[0]->location['city']);
        $this->assertSame(163, $result[0]->tickets_by_channel_product[0]['tickets']);
        $this->assertSame('2026-10-11T18:00', $result[0]->start_local);
    }
}
