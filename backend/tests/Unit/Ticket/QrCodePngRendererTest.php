<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\QrCodePngRenderer;
use Tests\TestCase;

class QrCodePngRendererTest extends TestCase
{
    public function test_renders_square_png_of_requested_size_without_imagick(): void
    {
        $png = (new QrCodePngRenderer)->render('A-C369BTS', 300);
        $image = imagecreatefromstring($png);

        self::assertSame(300, imagesx($image));
        self::assertSame(300, imagesy($image));
        self::assertSame(0xFFFFFF, imagecolorat($image, 0, 0) & 0xFFFFFF);
    }

    public function test_draws_finder_pattern_inside_quiet_zone(): void
    {
        $image = imagecreatefromstring((new QrCodePngRenderer)->render('A-C369BTS', 300));

        $module = intdiv(300, 21 + 4);
        $origin = intdiv(300 - 21 * $module, 2);

        self::assertSame(0x000000, imagecolorat($image, $origin + 1, $origin + 1) & 0xFFFFFF);
        self::assertSame(0xFFFFFF, imagecolorat($image, $origin - 2, $origin - 2) & 0xFFFFFF);
        self::assertSame(0xFFFFFF, imagecolorat($image, $origin + $module + 1, $origin + $module + 1) & 0xFFFFFF);
    }
}
