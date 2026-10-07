<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\ZplQrCodeRenderer;
use Tests\TestCase;

class ZplQrCodeRendererTest extends TestCase
{
    public function test_renders_version_1_code_with_requested_module_size(): void
    {
        $graphic = (new ZplQrCodeRenderer)->render('A-SFMVW8P', 12);

        self::assertSame(252, $graphic->size);
        self::assertStringStartsWith('^GFA,8064,8064,32,', $graphic->zpl);
    }

    public function test_first_rows_draw_the_top_left_finder_pattern(): void
    {
        $graphic = (new ZplQrCodeRenderer)->render('A-SFMVW8P', 12);
        $hex = substr($graphic->zpl, strlen('^GFA,8064,8064,32,'));

        $firstRow = substr($hex, 0, 64);
        self::assertStringStartsWith(str_repeat('F', 20).'F0', $firstRow);
        self::assertSame($firstRow, substr($hex, 11 * 64, 64));
    }

    public function test_module_size_is_chosen_to_fit_target_box(): void
    {
        $renderer = new ZplQrCodeRenderer;

        self::assertSame(252, $renderer->renderToFit('A-SFMVW8P', 252)->size);
        self::assertSame(231, $renderer->renderToFit('A-SFMVW8P', 240)->size);
        self::assertSame(21, $renderer->renderToFit('A-SFMVW8P', 5)->size);
    }
}
