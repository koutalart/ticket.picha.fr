<?php

declare(strict_types=1);

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
    public function test_ticket_sponsor_logo_is_an_event_image(): void
    {
        self::assertContains(ImageType::TICKET_SPONSOR_LOGO, ImageType::eventImageTypes());
        self::assertSame(EventDomainObject::class, ImageType::TICKET_SPONSOR_LOGO->getEntityType());
        self::assertSame([100, 40], ImageType::getMinimumDimensionsMap(ImageType::TICKET_SPONSOR_LOGO));
    }

    public function testEventShareImageBelongsToEvent(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_SHARE_IMAGE->getEntityType());
        $this->assertContains(ImageType::EVENT_SHARE_IMAGE, ImageType::eventImageTypes());
    }

    public function testEventShareImageMinimumDimensions(): void
    {
        $this->assertSame([600, 315], ImageType::getMinimumDimensionsMap(ImageType::EVENT_SHARE_IMAGE));
    }
}
