<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use HiEvents\Helper\AddressHelper;
use Tests\TestCase;

class AddressHelperTest extends TestCase
{
    public function test_empty_parts_are_skipped_without_a_leading_comma(): void
    {
        $address = [
            'venue_name' => 'Mayotte La 1ère',
            'address_line_1' => null,
            'address_line_2' => '',
            'city' => null,
            'state_or_region' => '  ',
            'zip_or_postal_code' => null,
            'country' => 'Mamoudzou',
        ];

        self::assertSame('Mayotte La 1ère, Mamoudzou', AddressHelper::formatAddress($address));
    }

    public function test_stray_commas_and_spaces_around_parts_are_removed(): void
    {
        self::assertSame(
            'Mayotte La 1ère, Les Hauts Vallons, Mamoudzou',
            AddressHelper::joinParts([' , ', ', Mayotte La 1ère', 'Les Hauts Vallons ,', null, ' Mamoudzou ']),
        );
    }

    public function test_full_address_is_unchanged(): void
    {
        self::assertSame(
            'Florida Palace, 162-164 boulevard Mireille Lauze, Marseille, 13010, FR',
            AddressHelper::formatAddress([
                'venue_name' => 'Florida Palace',
                'address_line_1' => '162-164 boulevard Mireille Lauze',
                'address_line_2' => null,
                'city' => 'Marseille',
                'state_or_region' => null,
                'zip_or_postal_code' => '13010',
                'country' => 'FR',
            ]),
        );
        self::assertSame('', AddressHelper::formatAddress(null));
    }
}
