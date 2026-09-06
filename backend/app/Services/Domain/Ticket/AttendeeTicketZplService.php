<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

class AttendeeTicketZplService
{
    public function generate(
        string $publicId,
        string $eventTitle,
        string $productTitle,
        string $attendeeName,
    ): string {
        $lines = [
            '^XA',
            '^CI28',
            '^PW609',
            '^LL406',
            '^FO24,24^A0N,36,36^FD' . $this->field($eventTitle, 32) . '^FS',
            '^FO24,70^A0N,28,28^FD' . $this->field($productTitle, 36) . '^FS',
        ];

        $name = $this->field($attendeeName, 36);
        if ($name !== '') {
            $lines[] = '^FO24,110^A0N,24,24^FD' . $name . '^FS';
        }

        $safeId = $this->field($publicId, 48);
        $lines[] = '^FO24,150^BQN,2,5^FDQA,' . $safeId . '^FS';
        $lines[] = '^FO280,170^A0N,28,28^FD' . $safeId . '^FS';
        $lines[] = '^XZ';

        return implode("\n", $lines);
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
