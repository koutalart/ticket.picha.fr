<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Http\Request\Attendee\CreateAttendeeRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class KioskSentinelEmailTest extends TestCase
{
    public function test_create_attendee_email_rule_accepts_invalid_tld_sentinel(): void
    {
        $validator = Validator::make(
            ['email' => 'kiosk.A-ABC1234@no-mail.picha.invalid'],
            ['email' => (new CreateAttendeeRequest)->rules()['email']],
        );

        self::assertTrue($validator->passes());
    }

    public function test_dns_email_rule_rejects_invalid_tld(): void
    {
        $validator = Validator::make(
            ['email' => 'kiosk.A-ABC1234@no-mail.picha.invalid'],
            ['email' => ['required', 'email:dns']],
        );

        self::assertFalse($validator->passes());
    }

    public function test_matches_sentinel_and_blank(): void
    {
        self::assertTrue(KioskSentinelEmail::isKioskSentinelEmail(null));
        self::assertTrue(KioskSentinelEmail::isKioskSentinelEmail(''));
        self::assertTrue(KioskSentinelEmail::isKioskSentinelEmail('kiosk.s12@no-mail.picha.invalid'));
        self::assertFalse(KioskSentinelEmail::isKioskSentinelEmail('jane@example.test'));
    }

    public function test_for_box_office_sale_uses_stable_domain(): void
    {
        self::assertSame('kiosk.s42@no-mail.picha.invalid', KioskSentinelEmail::forBoxOfficeSale(42));
        self::assertTrue(KioskSentinelEmail::isKioskSentinelEmail(KioskSentinelEmail::forBoxOfficeSale(42)));
    }
}
