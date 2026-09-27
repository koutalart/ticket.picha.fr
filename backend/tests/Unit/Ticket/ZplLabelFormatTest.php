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

    public function test_layout_shrinks_to_fit_label_length(): void
    {
        self::assertEqualsWithDelta(0.869, (new ZplLabelFormatDTO)->fitRatio(), 0.001);
        self::assertSame(1.0, (new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0))->fitRatio());
        self::assertSame(36, (new ZplLabelFormatDTO)->layout(42));
        self::assertSame(42, (new ZplLabelFormatDTO(width_mm: 80.0, length_mm: 101.0))->layout(42));
    }

    public function test_generator_fits_whole_layout_on_87_mm_label(): void
    {
        $zpl = $this->generate(null);

        self::assertStringContainsString('^FO36,146^A0N,40,40^FB447,2,2,L^FDMayotte', $zpl);
        self::assertStringContainsString('^FO30,601^GB495,2,2^FS', $zpl);
        self::assertStringContainsString('^FO104,667^A0N,18,18^FDpicha.fr', $zpl);

        self::assertStringContainsString("^LH38,0\n", $zpl);

        preg_match_all('/\^FO\d+,(\d+)/', $zpl, $matches);
        self::assertLessThan(695, max(array_map('intval', $matches[1])));
    }

    public function test_qr_code_is_enlarged_instead_of_shrunk_on_87_mm_label(): void
    {
        $zpl = $this->generate(null);

        self::assertStringContainsString('^FXQR:a_SAFE', $zpl);
        self::assertStringContainsString('^FO317,235^GFA,8064,8064,32,', $zpl);
        self::assertStringContainsString('^FO317,504^A0N,17,17^FB252,1,0,C^FDA_SAFE', $zpl);
        self::assertStringContainsString('^FO317,530^A0N,23,23^FB252,2,3,C^FDJane', $zpl);
        self::assertStringNotContainsString('^BQN', $zpl);
    }

    public function test_sponsor_wraps_inside_left_column_beside_qr_code(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Mayotte',
            productTitle: 'PASS',
            attendeeName: 'Jane',
            sponsorName: 'Digital Studio Mayotte',
        );

        self::assertStringContainsString('^FO90,556^A0N,16,16^FB217,2,2,L^FDSponsored by: Digital Studio Mayotte\\&^FS', $zpl);
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
        self::assertStringContainsString('^FO539,399^GFA,', $zpl);
        self::assertStringNotContainsString('^FO365,280', $zpl);
    }

    public function test_generator_keeps_reference_layout_on_80_by_101_mm_label(): void
    {
        $zpl = $this->generate(new ZplLabelFormatDTO(dpi: 203, width_mm: 80.0, length_mm: 101.0));

        self::assertStringContainsString("^PW639\n", $zpl);
        self::assertStringContainsString("^LH0,0\n", $zpl);
        self::assertStringContainsString('^FO42,168^A0N,46,46^FB515,2,2,L^FDMayotte', $zpl);
        self::assertStringContainsString('^FO365,270^GFA,8064,8064,32,', $zpl);
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
