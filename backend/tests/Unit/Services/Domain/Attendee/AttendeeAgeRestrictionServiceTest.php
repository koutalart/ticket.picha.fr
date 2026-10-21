<?php

namespace Tests\Unit\Services\Domain\Attendee;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\AgeRestrictionRule;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\AttendeeAgeRestrictionException;
use HiEvents\Services\Domain\Attendee\AttendeeAgeRestrictionService;
use Tests\TestCase;

class AttendeeAgeRestrictionServiceTest extends TestCase
{
    private AttendeeAgeRestrictionService $service;

    private CarbonImmutable $eventDay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AttendeeAgeRestrictionService();
        $this->eventDay = CarbonImmutable::parse('2026-12-20', 'UTC');
    }

    public function test_rule_is_disabled_by_default(): void
    {
        $settings = new EventSettingDomainObject();

        $this->assertSame(AgeRestrictionRule::NONE->value, $settings->getAgeRestrictionRule());
        $this->assertSame(18, $settings->getAgeRestrictionMinimumAge());
        $this->assertFalse($this->service->isEnabled($settings));
    }

    public function test_no_restriction_accepts_minors_and_missing_birth_dates(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::NONE),
            $this->eventDay,
            [0 => '2015-01-01', 1 => null, 2 => 'not-a-date'],
        );

        $this->assertTrue(true);
    }

    public function test_unknown_rule_value_is_treated_as_no_restriction(): void
    {
        $settings = (new EventSettingDomainObject())->setAgeRestrictionRule('SOMETHING_ELSE');

        $this->assertFalse($this->service->isEnabled($settings));
        $this->service->validate($settings, $this->eventDay, [0 => '2015-01-01']);
        $this->assertTrue(true);
    }

    public function test_accompanied_minor_with_adult_in_same_order_is_accepted(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::ACCOMPANIED_MINORS),
            $this->eventDay,
            [0 => '1985-06-10', 1 => '2014-02-03', 2 => '2018-09-30'],
        );

        $this->assertTrue(true);
    }

    public function test_accompanied_rule_rejects_order_with_only_minors(): void
    {
        $errors = $this->expectErrors(
            AgeRestrictionRule::ACCOMPANIED_MINORS,
            [0 => '2012-01-01', 1 => '2014-02-03'],
        );

        $message = 'Attendees under 18 must be accompanied by an adult registered in the same order.';
        $this->assertSame([
            'products.0.birth_date' => [$message],
            'products.1.birth_date' => [$message],
        ], $errors);
    }

    public function test_accompanied_rule_accepts_adults_only(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::ACCOMPANIED_MINORS),
            $this->eventDay,
            [0 => '1990-01-01', 1 => '1992-01-01'],
        );

        $this->assertTrue(true);
    }

    public function test_accompanied_rule_error_is_in_french_for_french_locale(): void
    {
        app()->setLocale('fr');

        $errors = $this->expectErrors(AgeRestrictionRule::ACCOMPANIED_MINORS, [0 => '2012-01-01']);

        $this->assertSame(
            ["Les participants de moins de 18 ans doivent être accompagnés d'un adulte inscrit dans la même commande."],
            $errors['products.0.birth_date'],
        );
    }

    public function test_minimum_age_rejects_any_minor_even_with_an_adult(): void
    {
        $errors = $this->expectErrors(
            AgeRestrictionRule::MINIMUM_AGE,
            [0 => '1980-01-01', 3 => '2010-07-14'],
        );

        $this->assertSame(
            ['products.3.birth_date' => ['This event is restricted to attendees aged 18 or over.']],
            $errors,
        );
    }

    public function test_minimum_age_accepts_adults(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::MINIMUM_AGE),
            $this->eventDay,
            [0 => '1980-01-01', 1 => '2000-12-31'],
        );

        $this->assertTrue(true);
    }

    public function test_birthday_on_event_day_counts_as_reached(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::MINIMUM_AGE),
            $this->eventDay,
            [0 => '2008-12-20'],
        );

        $this->assertTrue(true);
    }

    public function test_birthday_the_day_after_the_event_is_still_a_minor(): void
    {
        $errors = $this->expectErrors(AgeRestrictionRule::MINIMUM_AGE, [0 => '2008-12-21']);

        $this->assertArrayHasKey('products.0.birth_date', $errors);
    }

    public function test_birthday_on_event_day_makes_the_attendee_an_accompanying_adult(): void
    {
        $this->service->validate(
            $this->settings(AgeRestrictionRule::ACCOMPANIED_MINORS),
            $this->eventDay,
            [0 => '2008-12-20', 1 => '2016-04-01'],
        );

        $this->assertTrue(true);
    }

    public function test_custom_minimum_age_is_used(): void
    {
        $settings = $this->settings(AgeRestrictionRule::MINIMUM_AGE, 16);

        $this->service->validate($settings, $this->eventDay, [0 => '2010-12-20']);

        try {
            $this->service->validate($settings, $this->eventDay, [0 => '2010-12-21']);
            $this->fail('A 15 year old must be rejected when the minimum age is 16');
        } catch (AttendeeAgeRestrictionException $e) {
            $this->assertSame(
                ['products.0.birth_date' => ['This event is restricted to attendees aged 16 or over.']],
                $e->getErrors(),
            );
        }
    }

    public function test_leap_day_birthday_is_reached_on_first_of_march(): void
    {
        $settings = $this->settings(AgeRestrictionRule::MINIMUM_AGE);

        $this->service->validate($settings, CarbonImmutable::parse('2026-03-01', 'UTC'), [0 => '2008-02-29']);

        try {
            $this->service->validate($settings, CarbonImmutable::parse('2026-02-28', 'UTC'), [0 => '2008-02-29']);
            $this->fail('Someone born on 29 February 2008 is not 18 yet on 28 February 2026');
        } catch (AttendeeAgeRestrictionException) {
            $this->assertTrue(true);
        }
    }

    public function test_missing_birth_date_is_required_when_a_rule_is_active(): void
    {
        $errors = $this->expectErrors(AgeRestrictionRule::ACCOMPANIED_MINORS, [0 => '1980-01-01', 1 => null, 2 => '  ']);

        $this->assertSame([
            'products.1.birth_date' => ['Date of birth is required.'],
            'products.2.birth_date' => ['Date of birth is required.'],
        ], $errors);
    }

    public function test_invalid_or_future_birth_dates_are_rejected(): void
    {
        $errors = $this->expectErrors(
            AgeRestrictionRule::MINIMUM_AGE,
            [0 => '2001-02-30', 1 => '20/01/2000', 2 => '2027-01-01', 3 => '1850-01-01'],
        );

        $this->assertSame([
            'products.0.birth_date' => ['Please enter a valid date of birth.'],
            'products.1.birth_date' => ['Please enter a valid date of birth.'],
            'products.2.birth_date' => ['Please enter a valid date of birth.'],
            'products.3.birth_date' => ['Please enter a valid date of birth.'],
        ], $errors);
    }

    public function test_reference_date_is_the_event_start_day_in_the_event_timezone(): void
    {
        $event = (new EventDomainObject())
            ->setTimezone('Indian/Mayotte')
            ->setStartDate('2026-12-19 22:30:00');

        $this->assertSame('2026-12-20', $this->service->getReferenceDate($event)->format('Y-m-d'));
    }

    public function test_reference_date_falls_back_to_today_without_start_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC'));

        $event = (new EventDomainObject())->setTimezone('UTC');

        $this->assertSame('2026-10-22', $this->service->getReferenceDate($event)->format('Y-m-d'));

        CarbonImmutable::setTestNow();
    }

    public function test_normalize_birth_date(): void
    {
        $this->assertSame('1990-03-15', $this->service->normalizeBirthDate(' 1990-03-15 '));
        $this->assertNull($this->service->normalizeBirthDate(null));
        $this->assertNull($this->service->normalizeBirthDate(''));
        $this->assertNull($this->service->normalizeBirthDate('15/03/1990'));
    }

    private function settings(AgeRestrictionRule $rule, int $minimumAge = 18): EventSettingDomainObject
    {
        return (new EventSettingDomainObject())
            ->setAgeRestrictionRule($rule->value)
            ->setAgeRestrictionMinimumAge($minimumAge);
    }

    /**
     * @return array<string, string[]>
     */
    private function expectErrors(AgeRestrictionRule $rule, array $birthDates): array
    {
        try {
            $this->service->validate($this->settings($rule), $this->eventDay, $birthDates);
        } catch (AttendeeAgeRestrictionException $e) {
            return $e->getErrors();
        }

        $this->fail('An age restriction exception was expected');
    }
}
