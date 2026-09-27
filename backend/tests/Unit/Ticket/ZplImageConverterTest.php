<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use Tests\TestCase;

class ZplImageConverterTest extends TestCase
{
    public function test_fits_image_in_box_and_thresholds_to_black_and_white(): void
    {
        $graphic = (new ZplImageConverter)->convert($this->halfBlackPng(), 100, 100);

        self::assertNotNull($graphic);
        self::assertSame(100, $graphic->width);
        self::assertSame(50, $graphic->height);
        self::assertStringStartsWith('^GFA,650,650,13,FFFFFFFFFFFFC0000000000000', $graphic->zpl);
    }

    public function test_transparent_pixels_print_white(): void
    {
        $image = imagecreatetruecolor(16, 8);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $graphic = (new ZplImageConverter)->convert($png, 16, 8);

        self::assertSame('^GFA,16,16,2,'.str_repeat('0000', 8), $graphic?->zpl);
    }

    public function test_blank_margins_are_trimmed_before_fitting(): void
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 75, 25, 124, 74, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        $graphic = (new ZplImageConverter)->convert((string) ob_get_clean(), 80, 80);

        self::assertSame(80, $graphic?->width);
        self::assertSame(80, $graphic?->height);
        self::assertSame('^GFA,800,800,10,'.str_repeat('FF', 800), $graphic?->zpl);
    }

    public function test_unreadable_image_returns_null(): void
    {
        self::assertNull((new ZplImageConverter)->convert('not an image', 100, 100));
    }

    private function halfBlackPng(): string
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
