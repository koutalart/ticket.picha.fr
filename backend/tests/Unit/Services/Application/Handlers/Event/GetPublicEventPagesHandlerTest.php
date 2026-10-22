<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventPagesDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventPagesHandler;
use HiEvents\Services\Domain\Event\EventPagesService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GetPublicEventPagesHandlerTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;

    private ImageRepositoryInterface $imageRepository;

    private GetPublicEventPagesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->imageRepository = Mockery::mock(ImageRepositoryInterface::class);
        $this->imageRepository->shouldReceive('findWhere')->andReturn(new Collection);

        $this->handler = new GetPublicEventPagesHandler(
            $this->eventRepository,
            $this->imageRepository,
            new EventPagesService(app(HtmlPurifierService::class)),
        );
    }

    private function event(string $status): EventDomainObject
    {
        return (new EventDomainObject)
            ->setId(14)
            ->setAccountId(2)
            ->setStatus($status)
            ->setEventSettings((new EventSettingDomainObject)->setEventPages([
                'artists' => [],
                'practical_info' => [['title' => 'Accès', 'content' => '<p>Mamoudzou</p>']],
                'faq' => [],
            ]));
    }

    public function test_returns_pages_of_live_event(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->with(['id' => 14])->andReturn($this->event('LIVE'));

        $pages = $this->handler->handle(new GetPublicEventPagesDTO(eventId: 14));

        $this->assertSame(['infos-pratiques'], $pages->available_pages);
        $this->assertSame('Accès', $pages->practical_info[0]->title);
    }

    public function test_hides_draft_event_from_other_accounts(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($this->event('DRAFT'));

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new GetPublicEventPagesDTO(eventId: 14, authenticatedAccountId: 9));
    }

    public function test_shows_draft_event_to_its_account(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($this->event('DRAFT'));

        $pages = $this->handler->handle(new GetPublicEventPagesDTO(eventId: 14, authenticatedAccountId: 2));

        $this->assertSame(['infos-pratiques'], $pages->available_pages);
    }

    public function test_throws_when_event_is_missing(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new GetPublicEventPagesDTO(eventId: 14));
    }
}
