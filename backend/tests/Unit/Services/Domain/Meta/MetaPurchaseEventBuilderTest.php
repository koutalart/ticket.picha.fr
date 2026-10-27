<?php

namespace Tests\Unit\Services\Domain\Meta;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Services\Domain\Meta\MetaPurchaseEventBuilder;
use HiEvents\Services\Domain\Meta\MetaUserDataHasher;
use Tests\TestCase;

class MetaPurchaseEventBuilderTest extends TestCase
{
    public function test_builds_deduplicated_purchase_with_hashed_user_data(): void
    {
        $order = (new OrderDomainObject)
            ->setId(5)
            ->setShortId('o_abc123')
            ->setEmail('  Jane.Doe@Example.COM ')
            ->setFirstName('Jäne-Marie')
            ->setLastName(' Doe ')
            ->setCurrency('eur')
            ->setTotalGross(42.5)
            ->setAddress(['country' => 'YT'])
            ->setTrackingContext([
                'consent' => true,
                'fbp' => 'fb.1.1700000000000.123456789',
                'fbc' => 'fb.1.1700000000000.IwAR123',
                'client_ip_address' => '1.2.3.4',
                'client_user_agent' => 'Mozilla/5.0',
                'event_source_url' => 'https://ticket.picha.fr/event/9/concert?qr=abc1234',
            ])
            ->setOrderItems(collect([
                (new OrderItemDomainObject)->setQuantity(2),
                (new OrderItemDomainObject)->setQuantity(1),
            ]));

        $event = (new EventDomainObject)->setId(9)->setTitle('Concert');

        $payload = (new MetaPurchaseEventBuilder(new MetaUserDataHasher))->build($order, $event);

        $this->assertSame('Purchase', $payload['event_name']);
        $this->assertSame('order_o_abc123', $payload['event_id']);
        $this->assertSame('website', $payload['action_source']);
        $this->assertSame('https://ticket.picha.fr/event/9/concert?qr=abc1234', $payload['event_source_url']);

        $userData = $payload['user_data'];
        $this->assertSame([hash('sha256', 'jane.doe@example.com')], $userData['em']);
        $this->assertSame([hash('sha256', 'jänemarie')], $userData['fn']);
        $this->assertSame([hash('sha256', 'doe')], $userData['ln']);
        $this->assertSame([hash('sha256', 'yt')], $userData['country']);
        $this->assertSame('1.2.3.4', $userData['client_ip_address']);
        $this->assertSame('fb.1.1700000000000.123456789', $userData['fbp']);

        $this->assertSame('EUR', $payload['custom_data']['currency']);
        $this->assertSame(42.5, $payload['custom_data']['value']);
        $this->assertSame(['9'], $payload['custom_data']['content_ids']);
        $this->assertSame(3, $payload['custom_data']['num_items']);
        $this->assertSame('o_abc123', $payload['custom_data']['order_id']);
    }

    public function test_adds_phone_and_rebuilds_fbc_from_fbclid(): void
    {
        $order = (new OrderDomainObject)
            ->setShortId('o_y')
            ->setEmail('a@b.fr')
            ->setCurrency('EUR')
            ->setTotalGross(10)
            ->setTrackingContext(['consent' => true, 'fbclid' => 'IwAR_click']);

        $payload = (new MetaPurchaseEventBuilder(new MetaUserDataHasher))
            ->build($order, (new EventDomainObject)->setId(1)->setTitle('Gala'), '+262 639 25 02 06');

        $this->assertSame([hash('sha256', '262639250206')], $payload['user_data']['ph']);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR_click$/', $payload['user_data']['fbc']);
    }

    public function test_ignores_advertising_identifiers_without_consent(): void
    {
        $order = (new OrderDomainObject)
            ->setShortId('o_z')
            ->setEmail('a@b.fr')
            ->setCurrency('EUR')
            ->setTotalGross(10)
            ->setTrackingContext([
                'consent' => false,
                'fbp' => 'fb.1.1700000000000.1',
                'client_ip_address' => '1.2.3.4',
            ]);

        $payload = (new MetaPurchaseEventBuilder(new MetaUserDataHasher))
            ->build($order, (new EventDomainObject)->setId(1)->setTitle('Gala'));

        $this->assertSame(['em'], array_keys($payload['user_data']));
    }

    public function test_omits_missing_user_data(): void
    {
        $order = (new OrderDomainObject)
            ->setShortId('o_x')
            ->setEmail('not-an-email')
            ->setCurrency('EUR')
            ->setTotalGross(0)
            ->setTrackingContext(['consent' => true]);

        $payload = (new MetaPurchaseEventBuilder(new MetaUserDataHasher))
            ->build($order, (new EventDomainObject)->setId(1)->setTitle('Free event'));

        $this->assertSame([], $payload['user_data']);
        $this->assertArrayNotHasKey('num_items', $payload['custom_data']);
    }
}
