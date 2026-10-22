<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use HiEvents\Helper\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'mayotte mobile' => ['0639 12 34 56', 'FR', '+262639123456'],
            'mayotte landline' => ['02 69 61 00 00', 'FR', '+262269610000'],
            'reunion mobile' => ['0692 12 34 56', 'FR', '+262692123456'],
            'france mobile' => ['06 12 34 56 78', 'FR', '+33612345678'],
            'already e164' => ['+262 639 12 34 56', 'FR', '+262639123456'],
            'double zero' => ['00261 34 12 345 67', 'FR', '+261341234567'],
            'madagascar local' => ['034 12 345 67', 'MG', '+261341234567'],
            'denmark local' => ['20 12 34 56', 'DK', '+4520123456'],
            'too short' => ['12 34', 'FR', null],
            'empty' => ['  ', 'FR', null],
            'letters only' => ['n/a', 'FR', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalize(string $raw, string $country, ?string $expected): void
    {
        self::assertSame($expected, PhoneNumberNormalizer::normalize($raw, $country));
    }
}
