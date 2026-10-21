<?php

namespace Tests\Unit\Helper;

use HiEvents\Helper\SmsPhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsPhoneNormalizerTest extends TestCase
{
    public static function phoneProvider(): array
    {
        return [
            'mayotte international' => ['+262 639 12 34 56', '+262639123456'],
            'mayotte local' => ['0639123456', '+262639123456'],
            'mayotte local with spaces' => ['06 39 12 34 56', '+262639123456'],
            'reunion mobile local' => ['0692123456', '+262692123456'],
            'mayotte landline local' => ['0269612345', '+262269612345'],
            'mayotte double zero' => ['00262639123456', '+262639123456'],
            'france mobile local' => ['0612345678', '+33612345678'],
            'france international' => ['+33 6 12 34 56 78', '+33612345678'],
            'mayotte without leading zero' => ['639123456', '+262639123456'],
            'france without leading zero' => ['612345678', '+33612345678'],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, SmsPhoneNormalizer::normalize($input));
    }

    public function test_normalize_rejects_garbage(): void
    {
        $this->assertNull(SmsPhoneNormalizer::normalize('abc'));
        $this->assertNull(SmsPhoneNormalizer::normalize('123'));
    }

    public function test_matching_suffix_ignores_prefix_variants(): void
    {
        $this->assertSame('639123456', SmsPhoneNormalizer::matchingSuffix('+262639123456'));
        $this->assertSame('639123456', SmsPhoneNormalizer::matchingSuffix('06 39 12 34 56'));
        $this->assertNull(SmsPhoneNormalizer::matchingSuffix('12345'));
    }
}
