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
}
