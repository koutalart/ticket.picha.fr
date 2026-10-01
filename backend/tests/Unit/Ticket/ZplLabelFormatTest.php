<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
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

    public function test_layout_is_drawn_for_the_picha_roll_and_shrinks_on_smaller_labels(): void
    {
        self::assertSame(1.0, (new ZplLabelFormatDTO(length_mm: 87.0))->fitRatio());
        self::assertSame(1.0, (new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0))->fitRatio());
        self::assertEqualsWithDelta(0.761, (new ZplLabelFormatDTO(width_mm: 60.0, length_mm: 70.0))->fitRatio(), 0.001);
        self::assertSame(34, (new ZplLabelFormatDTO(length_mm: 87.0))->layout(34));
        self::assertSame(26, (new ZplLabelFormatDTO(width_mm: 60.0, length_mm: 70.0))->layout(34));
    }

    public function test_generator_centres_layout_on_wider_label(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0));

        self::assertStringContainsString("^PW639\n", $zpl);
        self::assertStringContainsString("^LL807\n", $zpl);
        self::assertStringContainsString("^LH4,0\n", $zpl);
        self::assertStringContainsString('^FO34,182^A0N,50,50^FDMayotte^FS', $zpl);
    }

    public function test_generator_uses_label_size_from_format(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 203, width_mm: 104.0, length_mm: 150.0));

        self::assertStringContainsString("^PW831\n", $zpl);
        self::assertStringContainsString("^LL1199\n", $zpl);
    }

    public function test_generator_scales_layout_for_300_dpi(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 300, length_mm: 87.0));

        self::assertStringContainsString("^PW933\n", $zpl);
        self::assertStringContainsString("^LL1028\n", $zpl);
        self::assertStringContainsString('^FO50,269^A0N,74,74^FDMayotte^FS', $zpl);
        self::assertStringContainsString('^FXQR:a_SAFE^FS', $zpl);
    }

    public function test_generator_shrinks_layout_on_smaller_label(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(width_mm: 60.0, length_mm: 70.0));

        self::assertStringContainsString('^FO26,138^A0N,38,38^FDMayotte^FS', $zpl);
    }

    public function test_qr_code_shrinks_with_layout_to_stay_in_its_frame_on_shorter_roll(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(width_mm: 79.0, length_mm: 75.0));

        self::assertStringContainsString("^LL599\n", $zpl);
        self::assertStringContainsString('^FO298,231^GB222,253,3,B,1^FS', $zpl);
        self::assertStringContainsString('^FO314,238^GFA,4536,4536,24,', $zpl);
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
