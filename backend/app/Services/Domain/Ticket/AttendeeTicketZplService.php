<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;

class AttendeeTicketZplService
{
    private const QR_MAGNIFICATION = 10;

    private const QR_MAX_MAGNIFICATION = 10;

    private const VENUE_MAX_LENGTH_BESIDE_NAME = 14;

    private const PICHA_PHONE = '06 39 78 07 73';

    /** @var array<string, int> */
    private const GRAPHICS = [
        'picha-logo.gfa' => 24,
        'icon-ticket.gfa' => 6,
        'icon-price.gfa' => 5,
        'icon-date.gfa' => 5,
        'icon-time.gfa' => 5,
        'icon-venue.gfa' => 5,
        'icon-megaphone.gfa' => 7,
    ];

    public function generate(
        string $publicId,
        string $eventTitle,
        string $productTitle,
        string $attendeeName,
        string $eventWhen = '',
        string $priceLabel = '',
        string $sponsorName = '',
        string $organizerName = '',
        string $organizerPhone = '',
        string $eventHours = '',
        string $venueName = '',
        string $venueCity = '',
        ?ZplLabelFormatDTO $labelFormat = null,
    ): string {
        $format = $labelFormat ?? new ZplLabelFormatDTO;
        $at = fn (int $x, int $y): string => '^FO'.$format->layout($x).','.$format->layout($y);
        $font = fn (int $height): string => '^A0N,'.$format->layout($height).','.$format->layout($height);
        $box = fn (int $width, int $height, int $thickness): string => '^GB'.$format->layout($width).','
            .$format->layout($height).','.$format->layout($thickness);
        $block = fn (int $width, int $lines, int $spacing, string $align): string => '^FB'.$format->layout($width)
            .','.$lines.','.$format->layout($spacing).','.$align;

        $safeId = $this->field($publicId, 48);
        $idLabel = $this->formatPublicIdLabel($safeId);
        $title = $this->field($eventTitle, 40);
        $product = $this->field($productTitle, 28);
        $when = $this->field($eventWhen, 24);
        $hours = $this->field($eventHours, 24);
        $name = $this->field($attendeeName, 40);
        $venue = $this->field($venueName, $name !== '' ? self::VENUE_MAX_LENGTH_BESIDE_NAME : 28);
        $city = $this->field($venueCity, 24);
        $price = $this->field($priceLabel, 16);

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

        $logo = $this->graphic('picha-logo.gfa', $at(38, 16));
        if ($logo !== []) {
            $lines = array_merge($lines, $logo);
        } else {
            $lines[] = $at(38, 28).$font(44).'^FDPICHA^FS';
        }

        $lines[] = $at(320, 18).$box(2, 100, 2).'^FS';

        $organizer = $this->field($organizerName, 28);
        if ($organizer !== '') {
            $lines[] = sprintf('%s%s%s^FD%s\\&^FS', $at(340, 22), $font(19), $block(270, 2, 2, 'L'), $organizer);
        }
        $phone = $this->field($organizerPhone, 20);
        if ($phone !== '') {
            $lines[] = $at(340, 70).$font(19).'^FD'.$phone.'^FS';
        }

        $lines[] = $at(42, 142).$font(18).'^FD'.$this->field(mb_strtoupper(__('Event')), 16).'^FS';
        $lines[] = sprintf('%s%s%s^FD%s\\&^FS', $at(42, 168), $font(46), $block(515, 2, 2, 'L'), $title);
        $lines[] = $at(42, 232).$box(82, 6, 6).'^FS';

        $lines = array_merge($lines, $this->graphic('icon-ticket.gfa', $at(42, 278)));
        $lines[] = $at(104, 276).$font(16).'^FD'.$this->field(mb_strtoupper(__('Ticket type')), 22).'^FS';
        $lines[] = $at(104, 300).$font(32).'^FD'.$product.'^FS';

        if ($price !== '') {
            $lines = array_merge($lines, $this->graphic('icon-price.gfa', $at(45, 350)));
            $lines[] = $at(104, 348).$font(16).'^FD'.$this->field(mb_strtoupper(__('Price')), 16).'^FS';
            $lines[] = $at(104, 372).$font(32).'^FD'.$price.'^FS';
        }

        if ($when !== '') {
            $lines = array_merge($lines, $this->graphic('icon-date.gfa', $at(44, 421)));
            $lines[] = $at(104, 419).$font(16).'^FD'.$this->field(mb_strtoupper(__('Date')), 16).'^FS';
            $lines[] = $at(104, 443).$font(31).'^FD'.$when.'^FS';
        }

        if ($hours !== '') {
            $lines = array_merge($lines, $this->graphic('icon-time.gfa', $at(44, 493)));
            $lines[] = $at(104, 491).$font(16).'^FD'.$this->field(mb_strtoupper(__('Time')), 16).'^FS';
            $lines[] = $at(104, 515).$font(31).'^FD'.$hours.'^FS';
        }

        if ($venue !== '' || $city !== '') {
            $lines = array_merge($lines, $this->graphic('icon-venue.gfa', $at(44, 565)));
            if ($venue !== '') {
                $lines[] = $at(104, 566).$font(34).'^FD'.$venue.'^FS';
            }
            if ($city !== '') {
                $lines[] = $at(104, 603).$font(20).'^FD'.$city.'^FS';
            }
        }

        $sponsor = $this->field($sponsorName, 32);
        if ($sponsor !== '' && strtoupper($sponsor) !== 'XXXXX') {
            $lines[] = $at(104, 640).$font(18).'^FD'.$this->field(__('Sponsored by: :name', ['name' => $sponsor]), 36).'^FS';
        }

        $lines[] = '^FB0';
        $lines[] = sprintf('%s^BQN,2,%d^FDQA,%s^FS', $at(365, 280), $this->qrMagnification($format), $safeId);
        $lines[] = sprintf('%s%s%s^FD%s\\&^FS', $at(370, 535), $font(20), $block(190, 1, 0, 'C'), $idLabel);

        if ($name !== '') {
            $lines[] = sprintf('%s%s%s^FD%s\\&^FS', $at(345, 570), $font(26), $block(240, 2, 4, 'C'), $name);
        }

        $lines[] = $at(34, 692).$box(570, 2, 2).'^FS';
        $lines = array_merge($lines, $this->graphic('icon-megaphone.gfa', $at(42, 718)));
        $lines[] = $at(120, 706).$font(19).'^FD'.$this->field(__('Your next event?'), 36).'^FS';
        $lines[] = $at(120, 733).$font(25).'^FD'.$this->field(__('PICHA Ticket takes care of it.'), 40).'^FS';
        $lines[] = $at(120, 768).$font(21).'^FDpicha.fr   >   '.self::PICHA_PHONE.'^FS';

        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    private function qrMagnification(ZplLabelFormatDTO $format): int
    {
        return max(1, min(self::QR_MAX_MAGNIFICATION, $format->scale(self::QR_MAGNIFICATION)));
    }

    /**
     * @return list<string>
     */
    private function graphic(string $filename, string $origin): array
    {
        $bytesPerRow = self::GRAPHICS[$filename] ?? 0;
        $path = base_path('resources/zpl/'.$filename);
        if ($bytesPerRow < 1 || ! is_readable($path)) {
            return [];
        }

        $hex = strtoupper(preg_replace('/\s+/', '', (string) file_get_contents($path)) ?? '');
        if ($hex === '') {
            return [];
        }

        $total = intdiv(strlen($hex), 2);

        return [
            $origin.'^GFA,'.$total.','.$total.','.$bytesPerRow.','.$hex.'^FS',
        ];
    }

    private function formatPublicIdLabel(string $id): string
    {
        return str_replace('-', ' - ', strtoupper($id));
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
