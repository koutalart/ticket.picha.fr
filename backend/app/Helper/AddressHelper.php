<?php

namespace HiEvents\Helper;

class AddressHelper
{
    public static function formatAddress(?array $address): string
    {
        if (is_null($address)) {
            return '';
        }

        return self::joinParts([
            $address['venue_name'] ?? null,
            $address['address_line_1'] ?? null,
            $address['address_line_2'] ?? null,
            $address['city'] ?? null,
            $address['state_or_region'] ?? null,
            $address['zip_or_postal_code'] ?? null,
            $address['country'] ?? null,
        ]);
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    public static function joinParts(array $parts): string
    {
        $cleaned = array_map(
            static fn ($part) => is_scalar($part) ? trim((string) $part, " \t\n\r\0\x0B,") : '',
            $parts,
        );

        return implode(', ', array_filter($cleaned, static fn (string $part) => $part !== ''));
    }
}
