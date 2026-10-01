<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;
use Tests\TestCase;

class AttendeeTicketZplServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
    }

    public function test_header_prints_organizer_and_sponsor_beside_divider(): void
    {
        $zpl = $this->generate(organizerName: 'Mayotte la 1ère', sponsorName: 'Bé digital');

        self::assertStringContainsString('^FO40,64^A0N,40,40^FDMayotte la 1ère^FS', $zpl);
        self::assertStringContainsString('^FO366,44^GB2,86,2^FS', $zpl);
        self::assertStringContainsString('^FO386,44^A0N,18,18^FB205,1,0,R^FDSponsor\&^FS', $zpl);
        self::assertStringContainsString('^FO386,70^A0N,32,32^FB205,2,2,R^FDBé digital\&^FS', $zpl);
    }

    public function test_event_logo_replaces_organizer_name_in_header(): void
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'A-C369BTS',
            eventTitle: 'JPO',
            productTitle: 'Pass VIP',
            attendeeName: '',
            organizerName: 'Mayotte la 1ère',
            labelFormat: new ZplLabelFormatDTO(length_mm: 87.0),
            eventLogoImage: $png,
        );

        self::assertStringContainsString('^FO40,44^GFA,1892,1892,22,FFFF', $zpl);
        self::assertStringNotContainsString('Mayotte la 1ère', $zpl);
    }

    public function test_sponsor_logo_is_right_aligned_under_sponsor_label(): void
    {
        $image = imagecreatetruecolor(200, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'A-C369BTS',
            eventTitle: 'JPO',
            productTitle: 'Pass VIP',
            attendeeName: '',
            sponsorName: 'Bé digital',
            labelFormat: new ZplLabelFormatDTO(length_mm: 87.0),
            sponsorLogoImage: $png,
        );

        self::assertStringContainsString('^FO366,44^GB2,86,2^FS', $zpl);
        self::assertStringContainsString('^FDSponsor\&^FS', $zpl);
        self::assertStringContainsString('^FO471,70^GFA,900,900,15,', $zpl);
        self::assertStringNotContainsString('Bé digital', $zpl);
    }

    public function test_long_organizer_name_uses_two_smaller_lines(): void
    {
        $zpl = $this->generate(organizerName: 'Association culturelle de Mamoudzou');

        self::assertStringContainsString('^FO40,56^A0N,30,30^FB310,2,2,L^FDAssociation culturelle de Mamoudzou\&^FS', $zpl);
    }

    public function test_long_values_are_cut_on_a_word_boundary(): void
    {
        $zpl = $this->generate(productTitle: 'Pass VIP accès backstage', venueName: 'Salle polyvalente de Cavani');

        self::assertStringContainsString('^FDPass VIP accès^FS', $zpl);
        self::assertStringContainsString('^FDSalle polyvalente^FS', $zpl);
    }

    public function test_header_omits_sponsor_block_without_sponsor(): void
    {
        $zpl = $this->generate(sponsorName: 'XXXXX');

        self::assertStringNotContainsString('^FO366,44', $zpl);
        self::assertStringNotContainsString('Sponsor', $zpl);
        self::assertStringNotContainsString('XXXXX', $zpl);
    }

    public function test_event_title_block(): void
    {
        $zpl = $this->generate();

        self::assertStringContainsString('^FO34,160^A0N,16,16^FDÉ V É N E M E N T^FS', $zpl);
        self::assertStringContainsString('^FO34,182^A0N,50,50^FDJPO Mayotte la 1ère^FS', $zpl);
        self::assertStringContainsString('^FO34,240^GB110,8,8,B,8^FS', $zpl);
    }

    public function test_long_event_title_wraps_on_two_smaller_lines(): void
    {
        $zpl = $this->generate(eventTitle: 'Journée portes ouvertes Mayotte la 1ère');

        self::assertStringContainsString('^FO34,182^A0N,36,36^FB563,2,2,L^FDJournée portes ouvertes Mayotte la 1ère\&^FS', $zpl);
        self::assertStringContainsString('^FO34,264^GB110,8,8,B,8^FS', $zpl);
    }

    public function test_detail_rows_with_venue_use_compact_pitch(): void
    {
        $zpl = $this->generate(venueName: 'Le 5/5', venueCity: 'Mamoudzou');

        self::assertStringContainsString('^FO30,282^GC62,2^FS', $zpl);
        self::assertStringContainsString('^FO43,291^GFA,', $zpl);
        self::assertStringContainsString("^FO108,288^A0N,15,15^FDT Y P E   D ' E N T R É E^FS", $zpl);
        self::assertStringContainsString('^FO108,308^A0N,30,27^FDPass VIP^FS', $zpl);
        self::assertStringContainsString('^FO30,348^GB2,1,1^FS', $zpl);
        self::assertStringContainsString('^FO108,378^A0N,30,27^FDSam. 5 sept. 2026^FS', $zpl);
        self::assertStringContainsString('^FO108,448^A0N,30,27^FD21h00^FS', $zpl);
        self::assertStringContainsString('^FO30,492^GC62,2^FS', $zpl);
        self::assertStringContainsString('^FO108,498^A0N,15,15^FDL I E U^FS', $zpl);
        self::assertStringContainsString('^FO108,518^A0N,30,27^FDLe 5/5, Mamoudzou^FS', $zpl);
    }

    public function test_detail_rows_without_venue_follow_design_pitch(): void
    {
        $zpl = $this->generate();

        self::assertStringContainsString('^FO30,374^GC62,2^FS', $zpl);
        self::assertStringContainsString('^FO30,466^GC62,2^FS', $zpl);
        self::assertStringContainsString('^FO30,359^GB2,1,1^FS', $zpl);
        self::assertStringNotContainsString('L I E U', $zpl);
    }

    public function test_icon_drawing_is_centred_in_its_circle(): void
    {
        $zpl = $this->generate();

        self::assertStringContainsString('^FO30,374^GC62,2^FS', $zpl);
        self::assertStringContainsString('^FO45,385^GFA,', $zpl);
    }

    public function test_qr_code_has_no_frame_and_shows_spaced_id_and_name(): void
    {
        $zpl = $this->generate();

        self::assertStringNotContainsString('^GB258,294', $zpl);
        self::assertStringContainsString('^FXQR:A-C369BTS^FS', $zpl);
        self::assertStringContainsString('^FO359,276^GFA,', $zpl);
        self::assertStringContainsString('^FO346,515^A0N,20,20^FB258,1,0,C^FDC 3 6 9 B T S\&^FS', $zpl);
        self::assertStringContainsString('^FO346,539^A0N,20,20^FB258,1,0,C^FDAnli Madi\&^FS', $zpl);
        self::assertStringNotContainsString('^BQN', $zpl);
    }

    public function test_footer_shows_picha_brand_and_site(): void
    {
        $zpl = $this->generate();

        self::assertStringContainsString('^FO30,574^GB8,2,2^FS', $zpl);
        self::assertStringContainsString('^FO48,586^A0N,18,18^FDBilletterie & gestion^FS', $zpl);
        self::assertStringContainsString('^FO48,610^GFA,', $zpl);
        self::assertStringContainsString('^FO268,600^GB2,80,2^FS', $zpl);
        self::assertStringContainsString('^FO306,624^GC32,2^FS', $zpl);
        self::assertStringContainsString('^FO352,624^A0N,32,32^FDticket.picha.fr^FS', $zpl);
        self::assertStringNotContainsString('Votre prochain', $zpl);
    }

    public function test_everything_fits_on_79_by_87_mm_label(): void
    {
        $zpl = $this->generate(venueName: 'Le 5/5', venueCity: 'Mamoudzou');

        self::assertStringContainsString("^PW631\n", $zpl);
        self::assertStringContainsString("^LL695\n", $zpl);
        self::assertStringContainsString("^MNM\n", $zpl);
        preg_match_all('/\^FO(\d+),(\d+)/', $zpl, $matches);
        self::assertLessThan(631, max(array_map('intval', $matches[1])));
        self::assertLessThan(680, max(array_map('intval', $matches[2])));
    }

    public function test_default_label_is_the_measured_84_mm_roll(): void
    {
        $zpl = (new AttendeeTicketZplService)->generate(
            publicId: 'A-C369BTS',
            eventTitle: 'JPO',
            productTitle: 'Pass VIP',
            attendeeName: '',
        );

        self::assertStringContainsString("^LL671\n", $zpl);
        preg_match_all('/\^FO\d+,(\d+)/', $zpl, $matches);
        self::assertLessThan(660, max(array_map('intval', $matches[1])));
    }

    public function test_escapes_control_characters_in_user_text(): void
    {
        $zpl = $this->generate(eventTitle: 'Night^Out', productTitle: 'VIP~Pass', attendeeName: 'Ann\\e');

        self::assertStringNotContainsString('Night^Out', $zpl);
        self::assertStringContainsString('^FDNight Out^FS', $zpl);
        self::assertStringContainsString('^FDVIP Pass^FS', $zpl);
        self::assertStringContainsString('^FDAnn e\&^FS', $zpl);
    }

    public function test_omits_name_when_empty(): void
    {
        $zpl = $this->generate(attendeeName: '');

        self::assertStringNotContainsString('^FO346,539', $zpl);
    }

    private function generate(
        string $eventTitle = 'JPO Mayotte la 1ère',
        string $productTitle = 'Pass VIP',
        string $attendeeName = 'Anli Madi',
        string $sponsorName = '',
        string $organizerName = '',
        string $venueName = '',
        string $venueCity = '',
    ): string {
        return (new AttendeeTicketZplService)->generate(
            publicId: 'A-C369BTS',
            eventTitle: $eventTitle,
            productTitle: $productTitle,
            attendeeName: $attendeeName,
            eventWhen: 'Sam. 5 sept. 2026',
            sponsorName: $sponsorName,
            organizerName: $organizerName,
            eventHours: '21h00',
            venueName: $venueName,
            venueCity: $venueCity,
            labelFormat: new ZplLabelFormatDTO(length_mm: 87.0),
        );
    }
}
