<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
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
