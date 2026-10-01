<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;
use HiEvents\Services\Domain\Ticket\DTO\ZplTicketDataDTO;

class AttendeeTicketZplService
{
    private const LABEL_WIDTH = 639;

    private const LABEL_LENGTH = 639;

    private const FOOTER_LOGO = 'picha-logo.gfa';

    private const FOOTER_TEXT = 'ticket.picha.fr';

    private const FOOTER_Y = 540;

    /** @var array<string, int> */
    private const GRAPHICS = [
        'picha-logo.gfa' => 24,
        'icon-ticket.gfa' => 6,
        'icon-date.gfa' => 5,
        'icon-time.gfa' => 5,
        'icon-venue.gfa' => 5,
        'icon-globe.gfa' => 5,
    ];

    public function __construct(
        private readonly ZplImageConverter $imageConverter,
    ) {}

    public function generate(ZplTicketDataDTO $ticket): string
    {
        $lines = [
            '^XA',
            '^CI28',
            '^PW'.self::LABEL_WIDTH,
            '^LL'.self::LABEL_LENGTH,
            '^LH0,0',
            '^LT0',
            '^FWN',
        ];

        $lines = array_merge(
            $lines,
            $this->header($ticket),
            $this->title($ticket->eventTitle),
            $this->details($ticket),
            $this->qrCode($ticket),
            $this->footer(),
        );
        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function header(ZplTicketDataDTO $ticket): array
    {
        $sponsor = $this->convertImage($ticket->sponsorLogo, 157, 67);

        if ($sponsor === null) {
            $logo = $this->convertImage($ticket->organizerLogo, 220, 100);
            if ($logo !== null) {
                return [$logo->toZpl(intdiv(self::LABEL_WIDTH - $logo->width, 2), 30 + intdiv(100 - $logo->height, 2))];
            }

            return [sprintf('^FO37,52^A0N,40,34^FB565,2,0,C^FD%s\\&^FS', $this->field($ticket->sellerName, 40))];
        }

        $lines = [];
        $logo = $this->convertImage($ticket->organizerLogo, 112, 94);
        if ($logo !== null) {
            $lines[] = $logo->toZpl(146 + intdiv(112 - $logo->width, 2), 34 + intdiv(94 - $logo->height, 2));
        } else {
            $lines[] = sprintf('^FO100,62^A0N,30,26^FB204,2,0,C^FD%s\\&^FS', $this->field($ticket->sellerName, 30));
        }

        $lines[] = '^FO318,63^GB3,62,3^FS';
        $lines[] = sprintf('^FO400,44^A0N,15,13^FB122,1,0,R^FD%s\\&^FS', $this->field(__('Sponsor'), 16));
        $lines[] = $sponsor->toZpl(366 + intdiv(157 - $sponsor->width, 2), 62);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function title(string $eventTitle): array
    {
        $title = $this->field($eventTitle, 38);
        $length = mb_strlen($title);
        [$y, $font] = match (true) {
            $length <= 22 => [168, '66,58'],
            $length <= 30 => [178, '52,44'],
            default => [186, '40,32'],
        };

        return [
            '^FO37,150^A0N,15,15^FD'.$this->spaced(mb_strtoupper(__('Event'))).'^FS',
            sprintf('^FO37,%d^A0N,%s^FD%s^FS', $y, $font, $title),
            '^FO37,236^GB100,6,3,B,1^FS',
        ];
    }

    /**
     * @return list<string>
     */
    private function details(ZplTicketDataDTO $ticket): array
    {
        $rows = [
            ['icon-ticket.gfa', $ticket->productTitle],
            ['icon-date.gfa', $ticket->eventDate],
            ['icon-time.gfa', $ticket->eventTime],
            ['icon-venue.gfa', $ticket->venue],
        ];

        $lines = [];
        $y = 268;
        foreach ($rows as [$icon, $value]) {
            $value = $this->field($value, 26);
            if ($value !== '') {
                $lines = array_merge($lines, $this->graphic($icon, 47, $y + 12));
                $length = mb_strlen($value);
                $font = match (true) {
                    $length <= 15 => '29,24',
                    $length <= 20 => '27,21',
                    default => '24,19',
                };
                $lines[] = sprintf('^FO99,%d^A0N,%s^FD%s^FS', $y + 16, $font, $value);
            }
            $y += 56;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function qrCode(ZplTicketDataDTO $ticket): array
    {
        $publicId = $this->field($ticket->publicId, 48);
        $lines = [];

        $seller = $this->field($ticket->sellerName, 30);
        if ($seller !== '') {
            $lines[] = sprintf(
                '^FO324,254^A0N,17,15^FB272,1,0,C^FD%s\\&^FS',
                $this->field(__('Seller: :name', ['name' => $seller]), 40),
            );
        }

        $lines[] = sprintf('^FO366,268^BQN,2,9^FDQA,%s^FS', $publicId);
        $lines[] = sprintf('^FO344,484^A0N,24,22^FB232,1,0,C^FD%s\\&^FS', $this->spaced($this->shortCode($publicId)));

        $name = $this->field($ticket->attendeeName, 28);
        if ($name !== '') {
            $lines[] = sprintf('^FO344,512^A0N,17,15^FB232,1,0,C^FD%s\\&^FS', $name);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function footer(): array
    {
        $y = self::FOOTER_Y;

        return array_merge(
            [sprintf('^FO36,%d^GB567,2,2^FS', $y)],
            $this->graphic(self::FOOTER_LOGO, 44, $y + 9),
            [
                sprintf('^FO266,%d^GB2,78,2^FS', $y + 12),
                sprintf('^FO292,%d^A0N,23,20^FD%s^FS', $y + 16, $this->field(__('Event management platform'), 40)),
            ],
            $this->graphic('icon-globe.gfa', 292, $y + 52),
            [sprintf('^FO334,%d^A0N,41,34^FD%s^FS', $y + 48, self::FOOTER_TEXT)],
        );
    }

    private function convertImage(?string $imageData, int $maxWidth, int $maxHeight): ?ZplGraphicDTO
    {
        if ($imageData === null || $imageData === '') {
            return null;
        }

        return $this->imageConverter->convert($imageData, $maxWidth, $maxHeight);
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

    private function shortCode(string $publicId): string
    {
        $parts = explode('-', $publicId, 2);

        return strtoupper($parts[1] ?? $parts[0]);
    }

    private function spaced(string $value): string
    {
        return implode(' ', mb_str_split($value));
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
