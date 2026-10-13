<?php

namespace Tests\Unit\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Application\Handlers\QrLink\QrLinkAttributesBuilder;
use Mockery;
use Tests\TestCase;

class QrLinkAttributesBuilderTest extends TestCase
{
    public function test_rejects_event_from_another_organizer(): void
    {
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 99, 'organizer_id' => 4])
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        (new QrLinkAttributesBuilder($eventRepository))->build($this->dto(eventId: 99));
    }

    public function test_cleans_text_and_drops_url_for_link_page(): void
    {
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);

        $attributes = (new QrLinkAttributesBuilder($eventRepository))->build($this->dto(
            eventId: 7,
            title: ' <b>Festival</b> ',
            description: '<script>x</script>',
            destinationUrl: 'https://instagram.com/picha',
            socialLinks: [['platform' => 'instagram', 'url' => 'https://instagram.com/picha', 'extra' => 'x']],
        ));

        $this->assertSame('Festival', $attributes['title']);
        $this->assertSame('x', $attributes['description']);
        $this->assertNull($attributes['destination_url']);
        $this->assertSame([['platform' => 'instagram', 'url' => 'https://instagram.com/picha']], $attributes['social_links']);
        $this->assertSame('LINK_PAGE', $attributes['destination_type']);
    }

    public function test_keeps_url_for_url_destination(): void
    {
        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class)))->build($this->dto(
            destinationType: QrLinkDestinationType::URL,
            destinationUrl: 'https://instagram.com/picha',
        ));

        $this->assertSame('https://instagram.com/picha', $attributes['destination_url']);
        $this->assertNull($attributes['event_id']);
    }

    private function dto(
        QrLinkDestinationType $destinationType = QrLinkDestinationType::LINK_PAGE,
        ?int $eventId = null,
        ?string $title = null,
        ?string $description = null,
        ?string $destinationUrl = null,
        ?array $socialLinks = null,
    ): UpsertQrLinkDTO {
        return new UpsertQrLinkDTO(
            accountId: 1,
            organizerId: 4,
            name: 'Affiche marché',
            destinationType: $destinationType,
            status: QrLinkStatus::ACTIVE,
            eventId: $eventId,
            destinationUrl: $destinationUrl,
            title: $title,
            description: $description,
            socialLinks: $socialLinks,
        );
    }
}
