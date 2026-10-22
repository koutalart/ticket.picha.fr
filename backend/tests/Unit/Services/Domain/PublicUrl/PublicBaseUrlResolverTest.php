<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\PublicUrl;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerResourcePublic;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakesPublicBaseUrl;
use Tests\TestCase;

class PublicBaseUrlResolverTest extends TestCase
{
    use FakesPublicBaseUrl;

    private OrganizerRepositoryInterface|MockInterface $organizerRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private PublicBaseUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://ticket.picha.fr/']);

        $this->organizerRepository = Mockery::mock(OrganizerRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->resolver = new PublicBaseUrlResolver($this->organizerRepository, $this->eventRepository);
    }

    public function test_organizer_with_a_custom_domain_uses_it(): void
    {
        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forOrganizer($this->organizer(8, 'mayottela1ere.fr')));
    }

    public function test_organizer_without_custom_domain_uses_the_platform(): void
    {
        $this->assertSame('https://ticket.picha.fr', $this->resolver->forOrganizer($this->organizer(3, null)));
        $this->assertSame('https://ticket.picha.fr', $this->resolver->forOrganizer(null));
    }

    public function test_archived_organizer_falls_back_to_the_platform(): void
    {
        $this->assertSame('https://ticket.picha.fr', $this->resolver->forOrganizer($this->organizer(8, 'mayottela1ere.fr', 'ARCHIVED')));
    }

    public function test_domain_is_normalized(): void
    {
        $this->assertSame('https://wawasalegy.com', $this->resolver->forOrganizer($this->organizer(9, 'https://www.WawaSalegy.com/')));
    }

    public function test_event_uses_its_loaded_organizer(): void
    {
        $event = (new EventDomainObject)->setId(14)->setOrganizerId(8)->setOrganizer($this->organizer(8, 'mayottela1ere.fr'));
        $this->organizerRepository->shouldNotReceive('findFirst');

        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forEvent($event));
    }

    public function test_event_without_loaded_organizer_looks_it_up_once(): void
    {
        $this->organizerRepository->shouldReceive('findFirst')->once()->with(8)->andReturn($this->organizer(8, 'mayottela1ere.fr'));
        $event = (new EventDomainObject)->setId(14)->setOrganizerId(8);

        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forEvent($event));
        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forEvent($event));
    }

    public function test_event_id_is_resolved_through_its_organizer(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->once()->with(14)->andReturn((new EventDomainObject)->setId(14)->setOrganizerId(8));
        $this->organizerRepository->shouldReceive('findFirst')->once()->with(8)->andReturn($this->organizer(8, 'mayottela1ere.fr'));

        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forEventId(14));
        $this->assertSame('https://mayottela1ere.fr', $this->resolver->forEventId(14));
    }

    public function test_unknown_event_uses_the_platform(): void
    {
        $this->eventRepository->shouldReceive('findFirst')->with(99)->andReturn(null);

        $this->assertSame('https://ticket.picha.fr', $this->resolver->forEventId(99));
        $this->assertSame('https://ticket.picha.fr', $this->resolver->forEventId(null));
    }

    public function test_event_url_keeps_the_same_path_on_the_custom_domain(): void
    {
        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr']);

        $event = (new EventDomainObject)->setId(14)->setOrganizerId(8)->setTitle('Le concert des générations');
        $platformEvent = (new EventDomainObject)->setId(4)->setOrganizerId(4)->setTitle('Halloween');

        $this->assertSame('https://mayottela1ere.fr/event/14/le-concert-des-generations', $event->getEventUrl());
        $this->assertSame('https://ticket.picha.fr/event/4/halloween', $platformEvent->getEventUrl());
        $this->assertSame(
            'https://mayottela1ere.fr/checkout/14/o_abc/summary',
            sprintf(Url::getPublicFrontEndUrlFromConfig(Url::ORDER_SUMMARY, $event), 14, 'o_abc'),
        );
    }

    public function test_qr_link_public_url_uses_the_organizer_domain(): void
    {
        $this->fakePublicBaseUrl([8 => 'mayottela1ere.fr']);

        $service = new QrLinkUrlService;

        $this->assertSame('https://mayottela1ere.fr/q/k4rm1ere', $service->getPublicUrl((new QrLinkDomainObject)->setOrganizerId(8)->setShortId('k4rm1ere')));
        $this->assertSame('https://ticket.picha.fr/q/abcd1234', $service->getPublicUrl((new QrLinkDomainObject)->setOrganizerId(4)->setShortId('abcd1234')));
    }

    public function test_public_organizer_resource_hides_the_domain_of_an_archived_organizer(): void
    {
        $live = (new OrganizerResourcePublic($this->organizer(8, 'mayottela1ere.fr')))->toArray(new Request);
        $archived = (new OrganizerResourcePublic($this->organizer(8, 'mayottela1ere.fr', 'ARCHIVED')))->toArray(new Request);

        $this->assertSame('mayottela1ere.fr', $live['custom_domain']);
        $this->assertNull($archived['custom_domain']);
    }

    private function organizer(int $id, ?string $domain, string $status = 'LIVE'): OrganizerDomainObject
    {
        return (new OrganizerDomainObject)->setId($id)->setName('Kultur m')->setStatus($status)->setCustomDomain($domain);
    }
}
