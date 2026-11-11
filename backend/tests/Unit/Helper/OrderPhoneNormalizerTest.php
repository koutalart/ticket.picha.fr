<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use HiEvents\Helper\OrderPhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderPhoneNormalizerTest extends TestCase
{
    public static function phones(): array
    {
        return [
            'Mayotte national' => ['0639 12 34 56', '+262639123456'],
            'Mayotte without leading zero' => ['639 12 34 56', '+262639123456'],
            'Mayotte written with +33' => ['+33 6 39 12 34 56', '+262639123456'],
            'Réunion' => ['0692.11.22.33', '+262692112233'],
            'France mobile' => ['06 12 34 56 78', '+33612345678'],
            'Madagascar international' => ['+261 34 12 345 67', '+261341234567'],
            'Double zero prefix' => ['0045 12 34 56 78', '+4512345678'],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_is_normalized_to_e164(string $raw, string $expected): void
    {
        self::assertSame($expected, OrderPhoneNormalizer::normalize($raw));
    }

    public function test_empty_or_unusable_phone_returns_null(): void
    {
        self::assertNull(OrderPhoneNormalizer::normalize(null));
        self::assertNull(OrderPhoneNormalizer::normalize('   '));
        self::assertNull(OrderPhoneNormalizer::normalize('12'));
        self::assertNull(OrderPhoneNormalizer::normalize('abc'));
    }
}
