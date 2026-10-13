<?php

namespace Tests\Unit\Services\Domain\Meta;

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

    public function test_returns_null_without_explicit_consent(): void
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
}
