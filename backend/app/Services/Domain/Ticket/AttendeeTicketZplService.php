<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

class AttendeeTicketZplService
{
    private const LABEL_WIDTH = 639;

    private const LABEL_LENGTH = 808;

    private const QR_MAGNIFICATION = 10;

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
    ): string {
        $safeId = $this->field($publicId, 48);
        $idLabel = $this->formatPublicIdLabel($safeId);
        $title = $this->field($eventTitle, 40);
        $product = $this->field($productTitle, 28);
        $when = $this->field($eventWhen, 24);
        $hours = $this->field($eventHours, 24);
        $venue = $this->field($venueName, 28);
        $city = $this->field($venueCity, 24);
        $price = $this->field($priceLabel, 16);

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.self::LABEL_WIDTH,
            '^LL'.self::LABEL_LENGTH,
            '^LH0,0',
            '^LT0',
            '^MNN',
            '^FWN',
        ];

        $logo = $this->graphic('picha-logo.gfa', 38, 16);
        if ($logo !== []) {
            $lines = array_merge($lines, $logo);
        } else {
            $lines[] = '^FO38,28^A0N,44,44^FDPICHA^FS';
        }

        $lines[] = '^FO320,18^GB2,100,2^FS';

        $organizer = $this->field($organizerName, 28);
        if ($organizer !== '') {
            $lines[] = sprintf('^FO340,22^A0N,19,19^FB270,2,2,L^FD%s\\&^FS', $organizer);
        }
        $phone = $this->field($organizerPhone, 20);
        if ($phone !== '') {
            $lines[] = '^FO340,70^A0N,19,19^FD'.$phone.'^FS';
        }

        $lines[] = '^FO42,142^A0N,18,18^FD'.$this->field(mb_strtoupper(__('Event')), 16).'^FS';
        $lines[] = sprintf('^FO42,168^A0N,46,46^FB515,2,2,L^FD%s\\&^FS', $title);
        $lines[] = '^FO42,232^GB82,6,6^FS';

        $lines = array_merge($lines, $this->graphic('icon-ticket.gfa', 42, 278));
        $lines[] = '^FO104,276^A0N,16,16^FD'.$this->field(mb_strtoupper(__('Ticket type')), 22).'^FS';
        $lines[] = '^FO104,300^A0N,32,32^FD'.$product.'^FS';

        if ($price !== '') {
            $lines = array_merge($lines, $this->graphic('icon-price.gfa', 45, 350));
            $lines[] = '^FO104,348^A0N,16,16^FD'.$this->field(mb_strtoupper(__('Price')), 16).'^FS';
            $lines[] = '^FO104,372^A0N,32,32^FD'.$price.'^FS';
        }

        if ($when !== '') {
            $lines = array_merge($lines, $this->graphic('icon-date.gfa', 44, 421));
            $lines[] = '^FO104,419^A0N,16,16^FD'.$this->field(mb_strtoupper(__('Date')), 16).'^FS';
            $lines[] = '^FO104,443^A0N,31,31^FD'.$when.'^FS';
        }

        if ($hours !== '') {
            $lines = array_merge($lines, $this->graphic('icon-time.gfa', 44, 493));
            $lines[] = '^FO104,491^A0N,16,16^FD'.$this->field(mb_strtoupper(__('Time')), 16).'^FS';
            $lines[] = '^FO104,515^A0N,31,31^FD'.$hours.'^FS';
        }

        if ($venue !== '' || $city !== '') {
            $lines = array_merge($lines, $this->graphic('icon-venue.gfa', 44, 565));
            if ($venue !== '') {
                $lines[] = '^FO104,566^A0N,34,34^FD'.$venue.'^FS';
            }
            if ($city !== '') {
                $lines[] = '^FO104,603^A0N,20,20^FD'.$city.'^FS';
            }
        }

        $sponsor = $this->field($sponsorName, 32);
        if ($sponsor !== '' && strtoupper($sponsor) !== 'XXXXX') {
            $lines[] = '^FO104,640^A0N,18,18^FD'.$this->field(__('Sponsored by: :name', ['name' => $sponsor]), 36).'^FS';
        }

        $lines[] = '^FB0';
        $lines[] = sprintf('^FO365,280^BQN,2,%d^FDQA,%s^FS', self::QR_MAGNIFICATION, $safeId);
        $lines[] = sprintf('^FO370,535^A0N,20,20^FB190,1,0,C^FD%s\\&^FS', $idLabel);

        $lines[] = '^FO34,692^GB570,2,2^FS';
        $lines = array_merge($lines, $this->graphic('icon-megaphone.gfa', 42, 718));
        $lines[] = '^FO120,706^A0N,19,19^FD'.$this->field(__('Your next event?'), 36).'^FS';
        $lines[] = '^FO120,733^A0N,25,25^FD'.$this->field(__('PICHA Ticket takes care of it.'), 40).'^FS';
        $lines[] = '^FO120,768^A0N,21,21^FDpicha.fr   >   '.self::PICHA_PHONE.'^FS';

        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function graphic(string $filename, int $x, int $y): array
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
            '^FO'.$x.','.$y.'^GFA,'.$total.','.$total.','.$bytesPerRow.','.$hex.'^FS',
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
