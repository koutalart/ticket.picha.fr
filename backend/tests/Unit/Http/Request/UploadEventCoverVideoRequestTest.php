<?php

namespace Tests\Unit\Http\Request;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Http\Request\Event\CreateEventImageRequest;
use HiEvents\Http\Request\Event\UploadEventCoverVideoRequest;
use HiEvents\Http\Request\Organizer\UploadOrganizerCoverVideoRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UploadEventCoverVideoRequestTest extends TestCase
{
    public function test_the_mobile_flag_is_optional_and_boolean(): void
    {
        $rules = (new UploadEventCoverVideoRequest)->rules();
        $mobileRules = ['mobile' => $rules['mobile']];

        $this->assertTrue(Validator::make([], $mobileRules)->passes());
        $this->assertTrue(Validator::make(['mobile' => '1'], $mobileRules)->passes());
        $this->assertTrue(Validator::make(['mobile' => false], $mobileRules)->passes());
        $this->assertFalse(Validator::make(['mobile' => 'maybe'], $mobileRules)->passes());
    }

    public function test_the_mobile_video_keeps_the_size_and_format_limits(): void
    {
        $rules = (new UploadEventCoverVideoRequest)->rules();

        $this->assertContains('max:15360', $rules['video']);
        $this->assertContains('mimetypes:video/mp4,video/webm', $rules['video']);
        $this->assertEquals($rules, (new UploadOrganizerCoverVideoRequest)->rules());
    }

    public function test_cover_videos_cannot_be_sent_through_the_image_endpoint(): void
    {
        $typeRule = (new CreateEventImageRequest)->rules()['type'];

        $this->assertFalse(Validator::make(['type' => ImageType::EVENT_COVER_VIDEO_MOBILE->name], ['type' => $typeRule])->passes());
        $this->assertFalse(Validator::make(['type' => ImageType::EVENT_COVER_VIDEO->name], ['type' => $typeRule])->passes());
        $this->assertTrue(Validator::make(['type' => ImageType::EVENT_COVER->name], ['type' => $typeRule])->passes());
    }
}
