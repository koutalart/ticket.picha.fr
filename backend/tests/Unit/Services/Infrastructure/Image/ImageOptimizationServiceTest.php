<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Image;

use HiEvents\Services\Infrastructure\Image\ImageOptimizationService;
use Imagick;
use ImagickPixel;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class ImageOptimizationServiceTest extends TestCase
{
    private ImageOptimizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not available');
        }

        $this->service = new ImageOptimizationService(m::mock(LoggerInterface::class)->shouldIgnoreMissing());
    }

    private function image(int $width, int $height, string $format, string $background = 'red'): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, new ImagickPixel($background));
        $image->addNoiseImage(Imagick::NOISE_RANDOM);
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    private function dimensions(string $blob): array
    {
        $image = new Imagick;
        $image->readImageBlob($blob);

        return [$image->getImageWidth(), $image->getImageHeight(), strtolower($image->getImageFormat())];
    }

    public function test_cover_is_converted_to_webp_and_resized(): void
    {
        $result = $this->service->optimize($this->image(2400, 1350, 'png'), 'image/png', 'EVENT_COVER');

        $this->assertNotNull($result);
        $this->assertSame('image/webp', $result->mimeType);
        $this->assertSame('webp', $result->extension);
        $this->assertSame(1600, $result->width);
        $this->assertSame(900, $result->height);
        $this->assertSame([1600, 900, 'webp'], $this->dimensions($result->contents));
    }

    public function test_share_image_is_converted_to_jpeg(): void
    {
        $result = $this->service->optimize($this->image(1280, 720, 'png'), 'image/png', 'EVENT_SHARE_IMAGE');

        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertSame(1200, $result->width);
        $this->assertSame(675, $result->height);
    }

    public function test_logo_keeps_png_and_is_downscaled(): void
    {
        $result = $this->service->optimize($this->image(2000, 1000, 'png', 'transparent'), 'image/png', 'ORGANIZER_LOGO');

        $this->assertSame('image/png', $result->mimeType);
        $this->assertSame(800, $result->width);
        $this->assertSame(400, $result->height);
    }

    public function test_small_logo_is_never_upscaled_nor_made_heavier(): void
    {
        $original = $this->image(100, 100, 'png');
        $result = $this->service->optimize($original, 'image/png', 'TICKET_LOGO');

        if ($result !== null) {
            $this->assertSame('image/png', $result->mimeType);
            $this->assertSame([100, 100], [$result->width, $result->height]);
            $this->assertLessThan(strlen($original), strlen($result->contents));
        }
        $this->assertTrue(true);
    }

    public function test_gif_and_invalid_data_are_skipped(): void
    {
        $this->assertNull($this->service->optimize($this->image(50, 50, 'gif'), 'image/gif', 'EVENT_COVER'));
        $this->assertNull($this->service->optimize('not an image', 'image/png', 'EVENT_COVER'));
    }

    public function test_unknown_type_is_treated_as_photo(): void
    {
        $result = $this->service->optimize($this->image(800, 600, 'jpeg'), 'image/jpeg', 'NOT_A_TYPE');

        $this->assertSame('image/webp', $result->mimeType);
    }
}
