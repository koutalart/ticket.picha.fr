<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
    public function test_event_share_image_belongs_to_event(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_SHARE_IMAGE->getEntityType());
        $this->assertContains(ImageType::EVENT_SHARE_IMAGE, ImageType::eventImageTypes());
    }

    public function test_event_share_image_minimum_dimensions(): void
    {
        $this->assertSame([600, 315], ImageType::getMinimumDimensionsMap(ImageType::EVENT_SHARE_IMAGE));
    }

    public function test_ticket_sponsor_logo_belongs_to_event(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::TICKET_SPONSOR_LOGO->getEntityType());
        $this->assertContains(ImageType::TICKET_SPONSOR_LOGO, ImageType::eventImageTypes());
        $this->assertSame([100, 50], ImageType::getMinimumDimensionsMap(ImageType::TICKET_SPONSOR_LOGO));
    }
}
