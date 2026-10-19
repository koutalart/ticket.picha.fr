<?php

namespace Tests\Unit\Services\Domain\Organizer;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Organizer\CustomDomainCurrentEventService;
use Mockery;
use Tests\TestCase;

class CustomDomainCurrentEventServiceTest extends TestCase
{
    public function test_no_redirect_when_the_organizer_has_not_enabled_it(): void
    {
        $repository = Mockery::mock(EventRepositoryInterface::class);
        $repository->shouldNotReceive('findWhere');

        $organizer = (new OrganizerDomainObject)->setId(4)->setSiteContent(['tagline' => 'Soirées à Mayotte']);

        $this->assertNull((new CustomDomainCurrentEventService($repository))->findEventToRedirectTo($organizer));
    }

    public function test_redirects_to_the_next_live_event_when_enabled(): void
    {
        $halloween = (new EventDomainObject)->setId(7)->setTitle('Halloween');
        $later = (new EventDomainObject)->setId(9)->setTitle('Nouvel an');

        $repository = Mockery::mock(EventRepositoryInterface::class);
        $repository->shouldReceive('findWhere')
            ->once()
            ->with(Mockery::on(fn (array $where) => $where['organizer_id'] === 4 && $where['status'] === 'LIVE'), Mockery::any(), Mockery::any())
            ->andReturn(collect([$halloween, $later]));

        $organizer = (new OrganizerDomainObject)->setId(4)->setSiteContent(['redirect_to_current_event' => true]);

        $this->assertSame($halloween, (new CustomDomainCurrentEventService($repository))->findEventToRedirectTo($organizer));
    }

    public function test_no_redirect_between_events(): void
    {
        $repository = Mockery::mock(EventRepositoryInterface::class);
        $repository->shouldReceive('findWhere')->once()->andReturn(collect());

        $organizer = (new OrganizerDomainObject)->setId(4)->setSiteContent(['redirect_to_current_event' => true]);

        $this->assertNull((new CustomDomainCurrentEventService($repository))->findEventToRedirectTo($organizer));
    }
}
