<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class HeroLogoImageTypeTest extends TestCase
{
    public function test_hero_logos_are_event_images(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_HERO_PARTNER_LOGO->getEntityType());
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_HERO_SIGNATURE_LOGO->getEntityType());
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_HERO_BRAND_LOGO->getEntityType());
    }

    public function test_hero_logos_accept_small_wide_logos(): void
    {
        $this->assertSame([100, 40], ImageType::getMinimumDimensionsMap(ImageType::EVENT_HERO_PARTNER_LOGO));
        $this->assertSame([100, 40], ImageType::getMinimumDimensionsMap(ImageType::EVENT_HERO_SIGNATURE_LOGO));
    }
}
