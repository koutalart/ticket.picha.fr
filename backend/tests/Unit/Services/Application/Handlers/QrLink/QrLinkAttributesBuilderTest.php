<?php

namespace Tests\Unit\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\QrCampaignDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Exceptions\QrLinkReferenceException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
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

        $this->expectException(QrLinkReferenceException::class);

        (new QrLinkAttributesBuilder($eventRepository, Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(QrCampaignRepositoryInterface::class)))->build($this->dto(eventId: 99));
    }

    public function test_cleans_text_and_drops_url_for_link_page(): void
    {
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('findFirstWhere')->andReturn(new EventDomainObject);

        $attributes = (new QrLinkAttributesBuilder($eventRepository, Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(QrCampaignRepositoryInterface::class)))->build($this->dto(
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
        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(QrCampaignRepositoryInterface::class)))->build($this->dto(
            destinationType: QrLinkDestinationType::URL,
            destinationUrl: 'https://instagram.com/picha',
        ));

        $this->assertSame('https://instagram.com/picha', $attributes['destination_url']);
        $this->assertNull($attributes['event_id']);
    }

    public function test_custom_template_requires_an_image_from_the_same_account(): void
    {
        $imageRepository = Mockery::mock(ImageRepositoryInterface::class);
        $imageRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 55, 'account_id' => 1])
            ->andReturnNull();

        $this->expectException(QrLinkReferenceException::class);

        (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), $imageRepository, Mockery::mock(QrCampaignRepositoryInterface::class)))
            ->build($this->dto(design: ['template' => 'custom', 'background_image_id' => 55]));
    }

    public function test_custom_template_stores_background_url_and_layout(): void
    {
        $imageRepository = Mockery::mock(ImageRepositoryInterface::class);
        $imageRepository->shouldReceive('findFirstWhere')->andReturn(
            (new ImageDomainObject)->setId(55)->setPath('qr_poster_background/story.webp')->setWidth(1067)->setHeight(2000)
        );

        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), $imageRepository, Mockery::mock(QrCampaignRepositoryInterface::class)))
            ->build($this->dto(design: [
                'template' => 'custom',
                'background_image_id' => 55,
                'qr_position' => 45,
                'qr_scale' => 55,
                'caption' => '<b>Scannez-moi</b>',
            ]));

        $this->assertSame('custom', $attributes['design']['template']);
        $this->assertSame(55, $attributes['design']['background_image_id']);
        $this->assertStringEndsWith('qr_poster_background/story.webp', $attributes['design']['background_image_url']);
        $this->assertSame(2000, $attributes['design']['background_height']);
        $this->assertSame(45, $attributes['design']['qr_position']);
        $this->assertSame('Scannez-moi', $attributes['design']['caption']);
    }

    public function test_preset_template_ignores_background_image(): void
    {
        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(QrCampaignRepositoryInterface::class)))
            ->build($this->dto(design: ['template' => 'story', 'background_image_id' => 55]));

        $this->assertSame(['template' => 'story'], $attributes['design']);
    }

    public function test_campaign_must_belong_to_the_organizer(): void
    {
        $campaigns = Mockery::mock(QrCampaignRepositoryInterface::class);
        $campaigns->shouldReceive('findFirstWhere')->with(['id' => 9, 'organizer_id' => 4])->andReturnNull();

        try {
            (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), Mockery::mock(ImageRepositoryInterface::class), $campaigns))
                ->build($this->dto(qrCampaignId: 9));
            $this->fail('Expected exception');
        } catch (QrLinkReferenceException $exception) {
            $this->assertSame('qr_campaign_id', $exception->getField());
        }
    }

    public function test_campaign_is_stored(): void
    {
        $campaigns = Mockery::mock(QrCampaignRepositoryInterface::class);
        $campaigns->shouldReceive('findFirstWhere')->andReturn((new QrCampaignDomainObject)->setId(9));

        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), Mockery::mock(ImageRepositoryInterface::class), $campaigns))
            ->build($this->dto(qrCampaignId: 9));

        $this->assertSame(9, $attributes['qr_campaign_id']);
    }

    public function test_app_destination_keeps_both_store_links_and_fallback(): void
    {
        $attributes = (new QrLinkAttributesBuilder(Mockery::mock(EventRepositoryInterface::class), Mockery::mock(ImageRepositoryInterface::class), Mockery::mock(QrCampaignRepositoryInterface::class)))
            ->build(new UpsertQrLinkDTO(
                accountId: 1,
                organizerId: 4,
                name: 'Appli',
                destinationType: QrLinkDestinationType::APP,
                status: QrLinkStatus::ACTIVE,
                destinationUrl: 'https://la1ere.franceinfo.fr/mayotte/',
                appStoreUrl: 'https://apps.apple.com/app/id885421673',
                playStoreUrl: 'https://play.google.com/store/apps/details?id=fr.francetv.outremer',
            ));

        $this->assertSame('APP', $attributes['destination_type']);
        $this->assertSame('https://la1ere.franceinfo.fr/mayotte/', $attributes['destination_url']);
        $this->assertSame('https://apps.apple.com/app/id885421673', $attributes['app_store_url']);
        $this->assertSame('https://play.google.com/store/apps/details?id=fr.francetv.outremer', $attributes['play_store_url']);
    }

    private function dto(
        QrLinkDestinationType $destinationType = QrLinkDestinationType::LINK_PAGE,
        ?int $eventId = null,
        ?string $title = null,
        ?string $description = null,
        ?string $destinationUrl = null,
        ?array $socialLinks = null,
        ?array $design = null,
        ?int $qrCampaignId = null,
    ): UpsertQrLinkDTO {
        return new UpsertQrLinkDTO(
            accountId: 1,
            organizerId: 4,
            name: 'Affiche marché',
            destinationType: $destinationType,
            status: QrLinkStatus::ACTIVE,
            eventId: $eventId,
            qrCampaignId: $qrCampaignId,
            destinationUrl: $destinationUrl,
            title: $title,
            description: $description,
            socialLinks: $socialLinks,
            design: $design,
        );
    }
}
