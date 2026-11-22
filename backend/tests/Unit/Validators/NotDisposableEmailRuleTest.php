<?php

namespace Tests\Unit\Validators;

use HiEvents\Validators\Rules\NotDisposableEmailRule;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NotDisposableEmailRuleTest extends TestCase
{
    public function test_refuses_throwaway_domains(): void
    {
        self::assertTrue(NotDisposableEmailRule::isDisposable('hievents486j2rjf@wuthering.cc'));
        self::assertTrue(NotDisposableEmailRule::isDisposable('x@Mailinator.com'));
        self::assertTrue(NotDisposableEmailRule::isDisposable('x@sub.mailinator.com'));
    }

    public function test_accepts_real_providers(): void
    {
        foreach (['jo@gmail.com', 'a@orange.fr', 'b@outlook.com', 'c@icloud.com', 'd@digit.yt', 'e@francetv.fr'] as $email) {
            self::assertFalse(NotDisposableEmailRule::isDisposable($email), $email);
        }
    }

    public function test_validation_message(): void
    {
        $validator = Validator::make(['email' => 'a@wuthering.cc'], ['email' => [new NotDisposableEmailRule]]);

        self::assertTrue($validator->fails());
    }
}
