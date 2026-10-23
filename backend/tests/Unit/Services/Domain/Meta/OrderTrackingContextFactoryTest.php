<?php

namespace Tests\Unit\Services\Domain\Meta;

use Carbon\CarbonImmutable;
use HiEvents\Services\Domain\Meta\OrderTrackingContextFactory;
use Tests\TestCase;

class OrderTrackingContextFactoryTest extends TestCase
{
    private OrderTrackingContextFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new OrderTrackingContextFactory;
    }

    public function test_returns_null_without_consent_and_without_attribution(): void
    {
        $this->assertNull($this->factory->create(null, '1.2.3.4', 'UA'));
        $this->assertNull($this->factory->create(['consent' => 'true', 'fbp' => 'fb.1.1700000000000.1'], '1.2.3.4', 'UA'));
        $this->assertNull($this->factory->create(['consent' => false], '1.2.3.4', 'UA'));
    }

    public function test_keeps_valid_values_and_drops_invalid_ones(): void
    {
        $context = $this->factory->create([
            'consent' => true,
            'fbp' => 'fb.1.1700000000000.987654321',
            'fbc' => '<script>',
            'event_source_url' => 'javascript:alert(1)',
        ], 'not-an-ip', str_repeat('a', 600));

        $this->assertSame([
            'consent' => true,
            'fbp' => 'fb.1.1700000000000.987654321',
            'client_user_agent' => str_repeat('a', 512),
        ], $context);
    }

    public function test_keeps_ip_and_source_url(): void
    {
        $context = $this->factory->create([
            'consent' => true,
            'event_source_url' => 'https://ticket.picha.fr/event/1/x?qr=abc',
        ], '2a01:cb00::1', 'UA');

        $this->assertSame('2a01:cb00::1', $context['client_ip_address']);
        $this->assertSame('https://ticket.picha.fr/event/1/x?qr=abc', $context['event_source_url']);
    }

    public function test_without_marketing_consent_only_exempt_audience_data_is_kept(): void
    {
        $context = $this->factory->create([
            'consent' => false,
            'consent_details' => ['categories' => ['necessary', 'audience'], 'version' => 2, 'timestamp' => $this->ms()],
            'fbp' => 'fb.1.1700000000000.987654321',
            'fbc' => 'fb.1.1700000000000.IwAR123',
            'fbclid' => 'IwAR123',
            'gclid' => 'Cj0KCQ',
            'gcl_aw' => 'GCL.1700000000.Cj0KCQ',
            'ttclid' => 'tt123',
            'wbraid' => 'wb123',
            'gbraid' => 'gb123',
            'event_source_url' => 'https://wawasalegy.com/event/8/x?fbclid=IwAR123',
            'first_touch' => $this->touch(['utm_source' => 'facebook', 'utm_medium' => 'paid']),
            'last_touch' => $this->touch(['utm_source' => 'instagram']),
        ], '1.2.3.4', 'UA', 'AbC123');

        $this->assertFalse($context['consent']);
        $this->assertSame('abc123', $context['qr_code']);
        $this->assertSame(['necessary', 'audience'], $context['consent_details']['categories']);
        $this->assertSame(2, $context['consent_details']['version']);
        $this->assertSame('facebook', $context['first_touch']['utm_source']);
        $this->assertSame('paid', $context['first_touch']['utm_medium']);
        $this->assertSame('instagram', $context['last_touch']['utm_source']);
        $this->assertSame('l.facebook.com', $context['last_touch']['referrer_domain']);
        $this->assertSame('/event/8/konka-wawa', $context['last_touch']['landing_path']);
        $this->assertArrayNotHasKey('referrer', $context['last_touch']);
        $this->assertArrayNotHasKey('landing_url', $context['last_touch']);

        foreach (['fbp', 'fbc', 'fbclid', 'gclid', 'gcl_aw', 'ttclid', 'wbraid', 'gbraid', 'event_source_url', 'client_ip_address', 'client_user_agent'] as $key) {
            $this->assertArrayNotHasKey($key, $context, $key);
        }
    }

    public function test_consent_flag_without_the_marketing_category_does_not_keep_ad_ids(): void
    {
        $context = $this->factory->create([
            'consent' => true,
            'consent_details' => ['categories' => ['necessary', 'audience'], 'version' => 2],
            'gclid' => 'Cj0KCQ',
            'fbc' => 'fb.1.1700000000000.IwAR123',
        ], '1.2.3.4', 'UA');

        $this->assertFalse($context['consent']);
        $this->assertArrayNotHasKey('gclid', $context);
        $this->assertArrayNotHasKey('fbc', $context);
    }

    public function test_with_marketing_consent_ad_ids_and_full_urls_are_kept(): void
    {
        $context = $this->factory->create([
            'consent' => true,
            'consent_details' => ['categories' => ['necessary', 'audience', 'marketing', 'spy'], 'version' => 2, 'timestamp' => $this->ms()],
            'fbp' => 'fb.1.1700000000000.1234567890',
            'fbc' => 'fb.1.1700000000000.IwAR123',
            'fbclid' => 'IwAR123',
            'gclid' => 'Cj0KCQ-abc_1',
            'gcl_aw' => 'GCL.1700000000.Cj0KCQ-abc_1',
            'ttclid' => 'E.C.P.abc',
            'wbraid' => 'wb123',
            'gbraid' => 'gb123',
            'first_touch' => $this->touch(),
            'last_touch' => $this->touch(),
        ], '1.2.3.4', 'UA');

        $this->assertTrue($context['consent']);
        $this->assertSame(['necessary', 'audience', 'marketing'], $context['consent_details']['categories']);
        $this->assertSame('fb.1.1700000000000.1234567890', $context['fbp']);
        $this->assertSame('fb.1.1700000000000.IwAR123', $context['fbc']);
        $this->assertSame('IwAR123', $context['fbclid']);
        $this->assertSame('Cj0KCQ-abc_1', $context['gclid']);
        $this->assertSame('GCL.1700000000.Cj0KCQ-abc_1', $context['gcl_aw']);
        $this->assertSame('E.C.P.abc', $context['ttclid']);
        $this->assertSame('wb123', $context['wbraid']);
        $this->assertSame('gb123', $context['gbraid']);
        $this->assertSame('https://l.facebook.com/l.php', $context['first_touch']['referrer']);
        $this->assertSame('https://wawasalegy.com/event/8/konka-wawa?utm_source=facebook', $context['first_touch']['landing_url']);
    }

    public function test_touches_are_whitelisted_and_length_limited(): void
    {
        $context = $this->factory->create([
            'first_touch' => [
                'utm_source' => str_repeat('s', 400),
                'utm_campaign' => '<b>noel</b>',
                'utm_term' => ['array'],
                'referrer_domain' => 'javascript:alert(1)',
                'landing_path' => '//evil.example/x',
                'captured_at' => 'not a date',
                'password' => 'secret',
            ],
            'last_touch' => [
                'referrer_domain' => 'WWW.Google.COM',
                'landing_path' => '/event/8/x?fbclid=abc',
                'captured_at' => CarbonImmutable::now()->addYears(5)->getTimestampMs(),
            ],
        ], null, null);

        $this->assertSame([
            'utm_source' => str_repeat('s', 150),
            'utm_campaign' => 'bnoel/b',
        ], $context['first_touch']);
        $this->assertSame(['referrer_domain' => 'google.com', 'landing_path' => '/event/8/x'], $context['last_touch']);
        $this->assertFalse($context['consent']);
    }

    public function test_invalid_qr_code_and_consent_details_are_dropped(): void
    {
        $context = $this->factory->create([
            'consent_details' => ['categories' => 'marketing', 'version' => 'x'],
            'last_touch' => ['utm_source' => 'sms'],
        ], null, null, 'https://x');

        $this->assertArrayNotHasKey('qr_code', $context);
        $this->assertArrayNotHasKey('consent_details', $context);
        $this->assertSame('sms', $context['last_touch']['utm_source']);
    }

    private function ms(): int
    {
        return CarbonImmutable::now()->subHour()->getTimestampMs();
    }

    private function touch(array $overrides = []): array
    {
        return array_merge([
            'utm_source' => 'facebook',
            'referrer_domain' => 'l.facebook.com',
            'referrer' => 'https://l.facebook.com/l.php',
            'landing_path' => '/event/8/konka-wawa',
            'landing_url' => 'https://wawasalegy.com/event/8/konka-wawa?utm_source=facebook',
            'captured_at' => $this->ms(),
        ], $overrides);
    }
}
