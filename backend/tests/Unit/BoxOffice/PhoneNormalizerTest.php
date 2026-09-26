<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Exceptions\MissingPhoneCallingCodeException;
use HiEvents\Helper\PhoneCallingCode;
use HiEvents\Helper\PhoneNormalizer;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_mayotte_local_number_uses_262_not_33(): void
    {
        self::assertSame('262', PhoneCallingCode::fromIso2('YT'));
        self::assertSame('+262639780773', PhoneNormalizer::normalize('639780773', '262'));
        self::assertStringNotContainsString('+33', PhoneNormalizer::normalize('639780773', '262'));
    }

    public function test_reunion_shares_262_with_mayotte(): void
    {
        self::assertSame('262', PhoneCallingCode::fromIso2('RE'));
        self::assertSame('+262639780773', PhoneNormalizer::normalize('639780773', '262'));
    }

    public function test_france_strips_national_trunk_zero(): void
    {
        self::assertSame('33', PhoneCallingCode::fromIso2('FR'));
        self::assertSame('+33612345678', PhoneNormalizer::normalize('612345678', '33'));
        self::assertSame('+33612345678', PhoneNormalizer::normalize('0612345678', '33'));
    }

    public function test_empty_phone_is_null_without_calling_code(): void
    {
        self::assertNull(PhoneNormalizer::normalize('', ''));
        self::assertNull(PhoneNormalizer::normalize('   ', ''));
    }

    public function test_already_e164_keeps_digits_without_calling_code(): void
    {
        self::assertSame('+33612345678', PhoneNormalizer::normalize('+33 6 12 34 56 78', ''));
    }

    public function test_local_phone_without_calling_code_throws(): void
    {
        $this->expectException(MissingPhoneCallingCodeException::class);
        PhoneNormalizer::normalize('639780773', '');
    }

    public function test_unknown_iso2_has_no_calling_code(): void
    {
        self::assertNull(PhoneCallingCode::fromIso2(null));
        self::assertNull(PhoneCallingCode::fromIso2(''));
        self::assertNull(PhoneCallingCode::fromIso2('ZZ'));
    }
}
