<?php

namespace Tests\Unit\Services\Application\Handlers\EventPresence;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventPresenceDomainObject;
use HiEvents\Exceptions\EventPresenceRejectedException;
use HiEvents\Repository\Interfaces\EventPresenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventPresence\DTO\SubmitEventPresenceDTO;
use HiEvents\Services\Application\Handlers\EventPresence\SubmitEventPresenceHandler;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery as m;
use Tests\TestCase;

class SubmitEventPresenceHandlerTest extends TestCase
{
    private EventPresenceRepositoryInterface $presenceRepository;

    private EventRepositoryInterface $eventRepository;

    private SubmitEventPresenceHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenceRepository = m::mock(EventPresenceRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $purifier = m::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn (?string $value) => $value === null ? null : preg_replace('#<script.*?</script>#is', '', $value));
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('services.feedback.event_ids', '')->andReturn('14');
        $config->shouldReceive('get')->with('app.key')->andReturn('key');

        $this->handler = new SubmitEventPresenceHandler($this->presenceRepository, $this->eventRepository, $purifier, $config);
    }

    public function test_refuses_an_event_outside_the_allowlist(): void
    {
        $this->presenceRepository->shouldNotReceive('create');
        $this->expectException(EventPresenceRejectedException::class);

        $this->handler->handle(new SubmitEventPresenceDTO(event_id: 7, first_name: 'Awa', phone: '0639780773'));
    }

    public function test_refuses_a_bad_phone(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->presenceRepository->shouldNotReceive('create');
        $this->expectException(EventPresenceRejectedException::class);

        $this->handler->handle(new SubmitEventPresenceDTO(event_id: 14, first_name: 'Awa', phone: '1234'));
    }

    public function test_stores_a_normalized_phone_and_clean_names(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $this->presenceRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => 14, 'phone' => '+262639780773'])->andReturnNull();
        $this->presenceRepository->shouldReceive('create')->once()->with(m::on(fn (array $a) => $a['phone'] === '+262639780773'
            && $a['first_name'] === 'Awa'
            && $a['last_name'] === null
            && $a['staff'] === 'Brayan'
            && $a['event_id'] === 14))->andReturn(new EventPresenceDomainObject);

        $this->handler->handle(new SubmitEventPresenceDTO(event_id: 14, first_name: ' Awa<script>x</script> ', phone: '06 39 78 07 73', staff: 'Brayan'));
    }

    public function test_same_phone_replaces_the_previous_entry(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);
        $existing = (new EventPresenceDomainObject)->setId(5);
        $this->presenceRepository->shouldReceive('findFirstWhere')->andReturn($existing);
        $this->presenceRepository->shouldNotReceive('create');
        $this->presenceRepository->shouldReceive('updateFromArray')->once()->with(5, m::on(fn (array $a) => $a['first_name'] === 'Moussa'))
            ->andReturn($existing);

        $this->handler->handle(new SubmitEventPresenceDTO(event_id: 14, first_name: 'Moussa', phone: '0612345678'));
    }
}
