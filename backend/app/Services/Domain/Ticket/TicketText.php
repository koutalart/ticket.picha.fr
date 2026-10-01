<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

class TicketText
{
    public static function displayId(string $publicId): string
    {
        $parts = explode('-', strtoupper($publicId), 2);

        return $parts[1] ?? $parts[0];
    }

    public static function spaced(string $text): string
    {
        return implode('   ', array_map(
            fn (string $word): string => implode(' ', mb_str_split($word)),
            explode(' ', $text),
        ));
    }
}
