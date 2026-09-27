<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;

class AttendeeTicketZplService
{
    private const QR_BOX = 231;

    private const SHORT_TITLE_MAX_LENGTH = 22;

    private const SHORT_ORGANIZER_MAX_LENGTH = 16;

    private const ROW_TOP = 282;

    private const ROW_CIRCLE = 62;

    private const PICHA_SITE = 'ticket.picha.fr';

    /** @var array<string, int> */
    private const GRAPHICS = [
        'picha-logo-footer.gfa' => 19,
        'icon-ticket.gfa' => 6,
        'icon-date.gfa' => 5,
        'icon-time.gfa' => 5,
        'icon-venue.gfa' => 5,
    ];

    public function __construct(
        private readonly ZplQrCodeRenderer $qrCodeRenderer = new ZplQrCodeRenderer,
    ) {}

    public function generate(
        string $publicId,
        string $eventTitle,
        string $productTitle,
        string $attendeeName,
        string $eventWhen = '',
        string $sponsorName = '',
        string $organizerName = '',
        string $eventHours = '',
        string $venueName = '',
        string $venueCity = '',
        ?ZplLabelFormatDTO $labelFormat = null,
    ): string {
        $format = $labelFormat ?? new ZplLabelFormatDTO;
        $d = fn (int $dots): int => $format->layout($dots);
        $at = fn (int $x, int $y): string => '^FO'.$d($x).','.$d($y);
        $font = fn (int $height, ?int $width = null): string => '^A0N,'.$d($height).','.$d($width ?? $height);
        $box = fn (int $width, int $height, int $thickness, int $rounding = 0): string => '^GB'.$d($width).','
            .$d($height).','.$d($thickness).($rounding > 0 ? ',B,'.$rounding : '');
        $block = fn (int $width, int $lines, int $spacing, string $align): string => '^FB'.$d($width)
            .','.$lines.','.$d($spacing).','.$align;

        $safeId = $this->field($publicId, 48);

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$format->widthDots(),
            '^LL'.$format->lengthDots(),
            '^LH'.$format->horizontalOffset().',0',
            '^LT0',
            '^MNM',
            '^FWN',
        ];

        $organizer = $this->words($organizerName, 44);
        if ($organizer !== '' && mb_strlen($organizer) <= self::SHORT_ORGANIZER_MAX_LENGTH) {
            $lines[] = $at(40, 64).$font(40).'^FD'.$organizer.'^FS';
        } elseif ($organizer !== '') {
            $lines[] = $at(40, 56).$font(30).$block(310, 2, 2, 'L').'^FD'.$organizer.'\&^FS';
        }

        $sponsor = $this->words($sponsorName, 24);
        if ($sponsor !== '' && strtoupper($sponsor) !== 'XXXXX') {
            $lines[] = $at(366, 44).$box(2, 86, 2).'^FS';
            $lines[] = $at(386, 44).$font(18).$block(205, 1, 0, 'R').'^FD'.$this->field(__('Sponsor'), 16).'\&^FS';
            $lines[] = $at(386, 70).$font(32).$block(205, 2, 2, 'R').'^FD'.$sponsor.'\&^FS';
        }

        $lines[] = $at(34, 160).$font(16).'^FD'.$this->spaced(mb_strtoupper($this->field(__('Event'), 16))).'^FS';

        $title = $this->field($eventTitle, 40);
        if (mb_strlen($title) <= self::SHORT_TITLE_MAX_LENGTH) {
            $lines[] = $at(34, 182).$font(50).'^FD'.$title.'^FS';
            $titleHeight = 50;
        } else {
            $lines[] = $at(34, 182).$font(36).$block(563, 2, 2, 'L').'^FD'.$title.'\&^FS';
            $titleHeight = 74;
        }
        $lines[] = $at(34, 182 + $titleHeight + 8).$box(110, 8, 8, 8).'^FS';

        $venue = implode(', ', array_filter([$this->field($venueName, 40), $this->field($venueCity, 40)]));
        $rows = array_values(array_filter([
            ['icon-ticket.gfa', __('Ticket type'), $productTitle],
            ['icon-date.gfa', __('Date'), $eventWhen],
            ['icon-time.gfa', __('Time'), $eventHours],
            ['icon-venue.gfa', __('Venue'), $venue],
        ], fn (array $row): bool => trim($row[2]) !== ''));
        $pitch = count($rows) > 3 ? 70 : 92;

        foreach ($rows as $index => [$icon, $label, $value]) {
            $top = self::ROW_TOP + $index * $pitch;
            $lines[] = $at(30, $top).'^GC'.$d(self::ROW_CIRCLE).','.$d(2).'^FS';
            [$iconWidth, $iconHeight] = $this->graphicSize($icon);
            $lines = array_merge($lines, $this->graphic(
                $icon,
                $at(30 + intdiv(self::ROW_CIRCLE - $iconWidth, 2), $top + intdiv(self::ROW_CIRCLE - $iconHeight, 2)),
            ));
            $lines[] = $at(108, $top + 6).$font(15).'^FD'.$this->spaced(mb_strtoupper($this->field($label, 20))).'^FS';
            $lines[] = $at(108, $top + 26).$font(30, 27).'^FD'.$this->words($value, 19).'^FS';

            if ($index < count($rows) - 1) {
                $separatorY = $top + self::ROW_CIRCLE + intdiv($pitch - self::ROW_CIRCLE, 2);
                for ($x = 30; $x <= 330; $x += 6) {
                    $lines[] = $at($x, $separatorY).$box(2, 1, 1).'^FS';
                }
            }
        }

        $lines[] = $at(346, 268).$box(258, 294, 3, 1).'^FS';
        $qr = $this->qrCodeRenderer->renderToFit($safeId, $format->scale(self::QR_BOX));
        $qrX = $d(346) + intdiv($d(258) - $qr->size, 2);
        $qrY = $d(276);
        $lines[] = '^FXQR:'.$safeId.'^FS';
        $lines[] = '^FO'.$qrX.','.$qrY.$qr->zpl.'^FS';
        $idY = $qrY + $qr->size + $d(8);
        $lines[] = '^FO'.$d(346).','.$idY.$font(20).$block(258, 1, 0, 'C').'^FD'.$this->spaced($this->displayId($safeId)).'\&^FS';

        $name = $this->words($attendeeName, 28);
        if ($name !== '') {
            $lines[] = '^FO'.$d(346).','.($idY + $d(24)).$font(20).$block(258, 1, 0, 'C').'^FD'.$name.'\&^FS';
        }

        for ($x = 30; $x <= 594; $x += 14) {
            $lines[] = $at($x, 574).$box(8, 2, 2).'^FS';
        }
        $lines[] = $at(48, 586).$font(18).'^FD'.$this->field(__('Ticketing & management'), 32).'^FS';
        $lines = array_merge($lines, $this->graphic('picha-logo-footer.gfa', $at(48, 610)));
        $lines[] = $at(268, 600).$box(2, 80, 2).'^FS';
        $lines[] = $at(306, 624).'^GC'.$d(32).','.$d(2).'^FS';
        $lines[] = $at(316, 624).'^GE'.$d(12).','.$d(32).','.$d(2).'^FS';
        $lines[] = $at(306, 639).$box(32, 2, 2).'^FS';
        $lines[] = $at(352, 624).$font(32).'^FD'.self::PICHA_SITE.'^FS';

        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function graphic(string $filename, string $origin): array
    {
        $hex = $this->graphicHex($filename);
        if ($hex === '') {
            return [];
        }

        $total = intdiv(strlen($hex), 2);

        return [
            $origin.'^GFA,'.$total.','.$total.','.self::GRAPHICS[$filename].','.$hex.'^FS',
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function graphicSize(string $filename): array
    {
        $bytesPerRow = self::GRAPHICS[$filename] ?? 0;
        $hex = $this->graphicHex($filename);
        if ($bytesPerRow < 1 || $hex === '') {
            return [0, 0];
        }

        return [$bytesPerRow * 8, intdiv(strlen($hex), 2 * $bytesPerRow)];
    }

    private function graphicHex(string $filename): string
    {
        $path = base_path('resources/zpl/'.$filename);
        if (! isset(self::GRAPHICS[$filename]) || ! is_readable($path)) {
            return '';
        }

        return strtoupper(preg_replace('/\s+/', '', (string) file_get_contents($path)) ?? '');
    }

    private function displayId(string $id): string
    {
        $parts = explode('-', strtoupper($id), 2);

        return $parts[1] ?? $parts[0];
    }

    private function spaced(string $text): string
    {
        return implode('   ', array_map(
            fn (string $word): string => implode(' ', mb_str_split($word)),
            explode(' ', $text),
        ));
    }

    private function words(string $value, int $maxLength): string
    {
        $clean = $this->field($value, PHP_INT_MAX);
        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        $cut = mb_substr($clean, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace >= intdiv($maxLength, 2)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' ,-');
    }

    private function field(string $value, int $maxLength): string
    {
        $clean = str_replace(['^', '~', '\\'], ' ', $value);
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxLength);
    }
}
