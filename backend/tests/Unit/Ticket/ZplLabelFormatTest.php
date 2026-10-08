<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Tests\TestCase;

class ZplLabelFormatTest extends TestCase
{
    public function test_default_format_matches_measured_roll_203_dpi_79_by_84_mm(): void
    {
        $format = new ZplLabelFormatDTO;

        self::assertSame(203, $format->dpi);
        self::assertSame(79.0, $format->width_mm);
        self::assertSame(84.0, $format->length_mm);
        self::assertSame(631, $format->widthDots());
        self::assertSame(671, $format->lengthDots());
        self::assertEqualsWithDelta(0.965, $format->fitRatio(), 0.001);
    }

    public function test_dots_are_derived_from_millimetres_and_dpi(): void
    {
        $format = new ZplLabelFormatDTO(dpi: 300, width_mm: 80.0, length_mm: 101.0);

        self::assertSame(945, $format->widthDots());
        self::assertSame(1193, $format->lengthDots());
    }

    public function test_layout_dots_scale_from_203_dpi_reference(): void
    {
        self::assertSame(365, (new ZplLabelFormatDTO)->scale(365));
        self::assertSame(539, (new ZplLabelFormatDTO(dpi: 300))->scale(365));
        self::assertSame(1, (new ZplLabelFormatDTO(dpi: 300))->scale(1));
    }

    public function test_layout_is_drawn_for_the_picha_roll_and_shrinks_on_smaller_labels(): void
    {
        self::assertSame(1.0, (new ZplLabelFormatDTO(length_mm: 87.0))->fitRatio());
        self::assertSame(1.0, (new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0))->fitRatio());
        self::assertEqualsWithDelta(0.761, (new ZplLabelFormatDTO(width_mm: 60.0, length_mm: 70.0))->fitRatio(), 0.001);
        self::assertSame(34, (new ZplLabelFormatDTO(length_mm: 87.0))->layout(34));
        self::assertSame(26, (new ZplLabelFormatDTO(width_mm: 60.0, length_mm: 70.0))->layout(34));
    }
}
