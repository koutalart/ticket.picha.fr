<?php

namespace Tests\Unit\Services\Domain\Social\Media;

use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Services\Domain\Social\Media\DTO\SocialMediaMetadataDTO;
use HiEvents\Services\Domain\Social\Media\SocialMediaRules;
use Tests\TestCase;

class SocialMediaRulesTest extends TestCase
{
    private SocialMediaRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new SocialMediaRules;
    }

    public function test_accepts_jpg_png_mp4_and_mov_only(): void
    {
        $this->assertSame(SocialMediaType::IMAGE, $this->rules->typeForMime('image/jpeg'));
        $this->assertSame(SocialMediaType::IMAGE, $this->rules->typeForMime('image/png'));
        $this->assertSame(SocialMediaType::VIDEO, $this->rules->typeForMime('video/mp4'));
        $this->assertSame(SocialMediaType::VIDEO, $this->rules->typeForMime('video/quicktime'));

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->typeForMime('image/gif');
    }

    public function test_video_is_limited_to_100_mb(): void
    {
        $this->rules->assertSize(SocialMediaType::VIDEO, 100 * 1024 * 1024);

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->assertSize(SocialMediaType::VIDEO, 100 * 1024 * 1024 + 1);
    }

    public function test_image_is_limited_to_8_mb(): void
    {
        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->assertSize(SocialMediaType::IMAGE, 8 * 1024 * 1024 + 1);
    }

    public function test_reel_must_be_vertical(): void
    {
        $this->rules->assertFitsFormat(SocialPostFormat::REEL, SocialMediaType::VIDEO, new SocialMediaMetadataDTO(1080, 1920, 15000));

        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->assertFitsFormat(SocialPostFormat::REEL, SocialMediaType::VIDEO, new SocialMediaMetadataDTO(1920, 1080, 15000));
    }

    public function test_reel_duration_is_checked(): void
    {
        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->assertFitsFormat(SocialPostFormat::REEL, SocialMediaType::VIDEO, new SocialMediaMetadataDTO(1080, 1920, 91000));
    }

    public function test_video_story_is_limited_to_60_seconds(): void
    {
        $this->expectException(InvalidSocialMediaFileException::class);
        $this->rules->assertFitsFormat(SocialPostFormat::STORY, SocialMediaType::VIDEO, new SocialMediaMetadataDTO(1080, 1920, 61000));
    }

    public function test_images_and_other_formats_are_not_checked_for_ratio(): void
    {
        $this->rules->assertFitsFormat(SocialPostFormat::IMAGE, SocialMediaType::IMAGE, new SocialMediaMetadataDTO(1920, 1080));
        $this->rules->assertFitsFormat(SocialPostFormat::CAROUSEL, SocialMediaType::VIDEO, new SocialMediaMetadataDTO(1920, 1080, 200000));

        $this->addToAssertionCount(1);
    }
}
