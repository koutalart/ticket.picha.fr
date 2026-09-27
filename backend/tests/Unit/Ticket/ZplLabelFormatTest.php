<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Tests\TestCase;

class ZplLabelFormatTest extends TestCase
{
    public function test_default_format_is_picha_roll_203_dpi_79_by_87_mm(): void
    {
        $format = new ZplLabelFormatDTO;

        self::assertSame(203, $format->dpi);
        self::assertSame(79.0, $format->width_mm);
        self::assertSame(87.0, $format->length_mm);
        self::assertSame(631, $format->widthDots());
        self::assertSame(695, $format->lengthDots());
    }

    public function test_generator_tracks_black_mark_media(): void
    {
        $zpl = $this->generate(null);

        self::assertStringContainsString("^MNM\n", $zpl);
        self::assertStringNotContainsString('^MNN', $zpl);
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

    public function test_generator_uses_label_size_from_format(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 203, width_mm: 104.0, length_mm: 150.0));

        self::assertStringContainsString("^PW831\n", $zpl);
        self::assertStringContainsString("^LL1199\n", $zpl);
    }

    public function test_generator_scales_layout_for_300_dpi(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 300, width_mm: 80.0, length_mm: 101.0));

        self::assertStringContainsString("^PW945\n", $zpl);
        self::assertStringContainsString("^LL1193\n", $zpl);
        self::assertStringContainsString('^FO62,248^A0N,68,68^FB761,2,3,L^FDMayotte', $zpl);
        self::assertStringContainsString('^FO539,414^BQN,2,10^FDQA,a_SAFE', $zpl);
        self::assertStringNotContainsString('^FO365,280', $zpl);
    }

    public function test_generator_keeps_reference_layout_on_80_by_101_mm_label(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 203, width_mm: 80.0, length_mm: 101.0));

        self::assertStringContainsString("^PW639\n", $zpl);
        self::assertStringContainsString('^FO42,168^A0N,46,46^FB515,2,2,L^FDMayotte', $zpl);
        self::assertStringContainsString('^FO365,280^BQN,2,10^FDQA,a_SAFE', $zpl);
    }

    private function generate(?ZplLabelFormatDTO $format): string
    {
        return (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: 'Jane',
            labelFormat: $format,
        );
    }
}
