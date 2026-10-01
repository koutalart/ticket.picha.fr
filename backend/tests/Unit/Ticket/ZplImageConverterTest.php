<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use Tests\TestCase;

class ZplImageConverterTest extends TestCase
{
    private function png(callable $draw, int $width = 40, int $height = 20, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        $draw($image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_converts_dark_pixels_to_ink(): void
    {
        $data = $this->png(fn ($image) => imagefilledrectangle($image, 0, 0, 7, 19, imagecolorallocate($image, 0, 0, 0)));

        $graphic = (new ZplImageConverter)->convert($data, 100, 100);

        self::assertNotNull($graphic);
        self::assertSame(40, $graphic->width);
        self::assertSame(20, $graphic->height);
        self::assertSame(5, $graphic->bytesPerRow);
        self::assertSame(str_repeat('FF00000000', 20), $graphic->hex);
    }

    public function test_scales_down_to_fit_box(): void
    {
        $graphic = (new ZplImageConverter)->convert($this->png(fn () => null, 400, 200), 100, 100);

        self::assertSame(100, $graphic->width);
        self::assertSame(50, $graphic->height);
        self::assertSame(13, $graphic->bytesPerRow);
    }

    public function test_light_logo_on_transparency_prints_as_silhouette(): void
    {
        $data = $this->png(
            fn ($image) => imagefilledrectangle($image, 0, 0, 7, 19, imagecolorallocatealpha($image, 255, 255, 255, 0)),
            transparent: true,
        );

        $graphic = (new ZplImageConverter)->convert($data, 100, 100);

        self::assertSame(str_repeat('FF00000000', 20), $graphic->hex);
    }

    public function test_to_zpl_builds_graphic_field(): void
    {
        $data = $this->png(fn ($image) => imagefilledrectangle($image, 0, 0, 7, 19, imagecolorallocate($image, 0, 0, 0)));

        $zpl = (new ZplImageConverter)->convert($data, 100, 100)->toZpl(10, 20);

        self::assertStringStartsWith('^FO10,20^GFA,100,100,5,FF00000000', $zpl);
        self::assertStringEndsWith('^FS', $zpl);
    }

    public function test_returns_null_for_invalid_data(): void
    {
        self::assertNull((new ZplImageConverter)->convert('not an image', 100, 100));
    }
}
