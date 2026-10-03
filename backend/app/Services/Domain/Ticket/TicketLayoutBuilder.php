<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\DTO\ZplGraphicDTO;
use HiEvents\Services\Domain\Ticket\Layout\TicketBoxElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketGraphicElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketLayout;
use HiEvents\Services\Domain\Ticket\Layout\TicketQrElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketTextElement;

class TicketLayoutBuilder
{
    public const SIZE = 639;

    private const FOOTER_LOGO = 'picha-logo.gfa';

    private const FOOTER_TEXT = 'ticket.picha.fr';

    private const FOOTER_Y = 540;

    private const QR_X = 366;

    private const QR_Y = 268;

    private const QR_MAGNIFICATION = 9;

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

    public function build(TicketDataDTO $ticket): TicketLayout
    {
        return new TicketLayout(
            width: self::SIZE,
            height: self::SIZE,
            elements: array_merge(
                $this->header($ticket),
                $this->title($ticket->eventTitle),
                $this->details($ticket),
                $this->qrCode($ticket),
                $this->footer(),
            ),
        );
    }

    /**
     * @return list<TicketTextElement|TicketBoxElement|TicketGraphicElement>
     */
    private function header(TicketDataDTO $ticket): array
    {
        $sponsor = $this->convertImage($ticket->sponsorLogo, 157, 67);

        if ($sponsor === null) {
            $logo = $this->convertImage($ticket->organizerLogo, 220, 100);
            if ($logo !== null) {
                return [new TicketGraphicElement(intdiv(self::SIZE - $logo->width, 2), 30 + intdiv(100 - $logo->height, 2), $logo)];
            }

            return [new TicketTextElement(37, 52, 40, 34, $this->field($ticket->sellerName, 40), 565, 'C', 2)];
        }

        $elements = [];
        $logo = $this->convertImage($ticket->organizerLogo, 112, 94);
        $elements[] = $logo !== null
            ? new TicketGraphicElement(146 + intdiv(112 - $logo->width, 2), 34 + intdiv(94 - $logo->height, 2), $logo)
            : new TicketTextElement(100, 62, 30, 26, $this->field($ticket->sellerName, 30), 204, 'C', 2);

        $elements[] = new TicketBoxElement(318, 63, 3, 62, 3);
        $elements[] = new TicketTextElement(400, 44, 15, 13, $this->field(__('Sponsor'), 16), 122, 'R');
        $elements[] = new TicketGraphicElement(366 + intdiv(157 - $sponsor->width, 2), 62, $sponsor);

        return $elements;
    }

    /**
     * @return list<TicketTextElement|TicketBoxElement>
     */
    private function title(string $eventTitle): array
    {
        $title = $this->field($eventTitle, 38);
        $length = mb_strlen($title);
        [$y, $height, $width] = match (true) {
            $length <= 22 => [168, 66, 58],
            $length <= 30 => [178, 52, 44],
            default => [186, 40, 32],
        };

        return [
            new TicketTextElement(37, 150, 15, 15, $this->spaced(mb_strtoupper(__('Event')))),
            new TicketTextElement(37, $y, $height, $width, $title),
            new TicketBoxElement(37, 236, 100, 6, 3, 1),
        ];
    }

    /**
     * @return list<TicketTextElement|TicketGraphicElement>
     */
    private function details(TicketDataDTO $ticket): array
    {
        $rows = [
            ['icon-ticket.gfa', $ticket->productTitle],
            ['icon-date.gfa', $ticket->eventDate],
            ['icon-time.gfa', $ticket->eventTime],
            ['icon-venue.gfa', $ticket->venue],
        ];

        $elements = [];
        $y = 268;
        foreach ($rows as [$icon, $value]) {
            $value = $this->field($value, 48);
            if ($value !== '') {
                $graphic = $this->asset($icon);
                if ($graphic !== null) {
                    $elements[] = new TicketGraphicElement(47, $y + 12, $graphic);
                }
                $length = mb_strlen($value);
                $elements[] = match (true) {
                    $length <= 15 => new TicketTextElement(99, $y + 16, 29, 24, $value),
                    $length <= 20 => new TicketTextElement(99, $y + 16, 27, 21, $value),
                    $length <= 26 => new TicketTextElement(99, $y + 16, 24, 19, $value),
                    default => new TicketTextElement(99, $y + 6, 22, 18, $value, 255, 'L', 2),
                };
            }
            $y += 56;
        }

        return $elements;
    }

    /**
     * @return list<TicketTextElement|TicketBoxElement|TicketQrElement>
     */
    private function qrCode(TicketDataDTO $ticket): array
    {
        $publicId = $this->field($ticket->publicId, 48);
        $elements = [];

        $seller = $this->field($ticket->sellerName, 30);
        if ($seller !== '') {
            $elements[] = new TicketTextElement(324, 254, 17, 15, $this->field(__('Seller: :name', ['name' => $seller]), 40), 272, 'C');
        }

        if ($ticket->status !== TicketDataDTO::STATUS_VALID) {
            $label = $ticket->status === TicketDataDTO::STATUS_CANCELLED
                ? __('Cancelled')
                : __('Awaiting payment');
            $elements[] = new TicketBoxElement(self::QR_X, self::QR_Y + 10, 189, 189, 4);
            $elements[] = new TicketTextElement(self::QR_X, self::QR_Y + 82, 30, 25, $this->field(mb_strtoupper($label), 40), 189, 'C', 2);

            return $elements;
        }

        $elements[] = new TicketQrElement(self::QR_X, self::QR_Y, self::QR_MAGNIFICATION, $publicId);
        $elements[] = new TicketTextElement(344, 484, 24, 22, $this->spaced($this->shortCode($publicId)), 232, 'C');

        $name = $this->field($ticket->attendeeName, 28);
        if ($name !== '') {
            $elements[] = new TicketTextElement(344, 512, 17, 15, $name, 232, 'C');
        }

        return $elements;
    }

    /**
     * @return list<TicketTextElement|TicketBoxElement|TicketGraphicElement>
     */
    private function footer(): array
    {
        $y = self::FOOTER_Y;
        $elements = [new TicketBoxElement(36, $y, 567, 2, 2)];

        if ($logo = $this->asset(self::FOOTER_LOGO)) {
            $elements[] = new TicketGraphicElement(44, $y + 9, $logo);
        }
        $elements[] = new TicketBoxElement(266, $y + 12, 2, 78, 2);
        $elements[] = new TicketTextElement(292, $y + 16, 23, 20, $this->field(__('Event management platform'), 40));
        if ($globe = $this->asset('icon-globe.gfa')) {
            $elements[] = new TicketGraphicElement(292, $y + 52, $globe);
        }
        $elements[] = new TicketTextElement(334, $y + 48, 41, 34, self::FOOTER_TEXT);

        return $elements;
    }

    private function convertImage(?string $imageData, int $maxWidth, int $maxHeight): ?ZplGraphicDTO
    {
        if ($imageData === null || $imageData === '') {
            return null;
        }

        return $this->imageConverter->convert($imageData, $maxWidth, $maxHeight);
    }

    private function asset(string $filename): ?ZplGraphicDTO
    {
        $bytesPerRow = self::GRAPHICS[$filename] ?? 0;
        $path = base_path('resources/zpl/'.$filename);
        if ($bytesPerRow < 1 || ! is_readable($path)) {
            return null;
        }

        $hex = strtoupper(preg_replace('/\s+/', '', (string) file_get_contents($path)) ?? '');
        if ($hex === '') {
            return null;
        }

        return new ZplGraphicDTO(
            width: $bytesPerRow * 8,
            height: intdiv(strlen($hex), 2 * $bytesPerRow),
            bytesPerRow: $bytesPerRow,
            hex: $hex,
        );
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
