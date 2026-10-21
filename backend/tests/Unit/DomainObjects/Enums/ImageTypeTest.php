<?php

declare(strict_types=1);

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
    public function test_ticket_sponsor_logo_is_an_event_image(): void
    {
        self::assertContains(ImageType::TICKET_SPONSOR_LOGO, ImageType::eventImageTypes());
        self::assertSame(EventDomainObject::class, ImageType::TICKET_SPONSOR_LOGO->getEntityType());
        self::assertSame([100, 40], ImageType::getMinimumDimensionsMap(ImageType::TICKET_SPONSOR_LOGO));
    }

    public function test_event_share_image_belongs_to_event(): void
    {
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_SHARE_IMAGE->getEntityType());
        $this->assertContains(ImageType::EVENT_SHARE_IMAGE, ImageType::eventImageTypes());
    }

    public function test_event_share_image_minimum_dimensions(): void
    {
        $this->assertSame([600, 315], ImageType::getMinimumDimensionsMap(ImageType::EVENT_SHARE_IMAGE));
    }

    public function test_mobile_cover_videos_belong_to_their_entity(): void
    {
        $this->assertContains(ImageType::EVENT_COVER_VIDEO_MOBILE, ImageType::eventImageTypes());
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_COVER_VIDEO_MOBILE->getEntityType());
        $this->assertContains(ImageType::ORGANIZER_COVER_VIDEO_MOBILE, ImageType::organizerImageTypes());
        $this->assertSame(OrganizerDomainObject::class, ImageType::ORGANIZER_COVER_VIDEO_MOBILE->getEntityType());
    }

    public function test_cover_video_types_are_flagged_as_videos(): void
    {
        $this->assertTrue(ImageType::EVENT_COVER_VIDEO->isCoverVideo());
        $this->assertTrue(ImageType::EVENT_COVER_VIDEO_MOBILE->isCoverVideo());
        $this->assertTrue(ImageType::ORGANIZER_COVER_VIDEO->isCoverVideo());
        $this->assertTrue(ImageType::ORGANIZER_COVER_VIDEO_MOBILE->isCoverVideo());
        $this->assertFalse(ImageType::EVENT_COVER->isCoverVideo());
        $this->assertFalse(ImageType::EVENT_FLYER->isCoverVideo());
    }

}
