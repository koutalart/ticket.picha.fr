<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

class BoxOfficeCartSingleOrderTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    /**
     * @return array{0: Product, 1: ProductPrice}
     */
    private function addProduct(Event $event, string $title, float $price): array
    {
        $product = Product::create([
            'event_id' => $event->id,
            'title' => $title,
            'product_type' => ProductType::TICKET->name,
            'type' => $price > 0 ? 'PAID' : 'FREE',
            'order' => 2,
        ]);

        $productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => $price,
            'initial_quantity_available' => 100,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        $this->attachCheckInList($event, $product);

        return [$product, $productPrice];
    }

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     */
    private function sell(Event $event, int $agentId, array $items, float $amount, ?string $key = null, BoxOfficePaymentMethod $method = BoxOfficePaymentMethod::CASH): mixed
    {
        return app(CreateBoxOfficeSaleHandler::class)->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $agentId,
            product_id: $items[0]->product_id,
            product_price_id: $items[0]->product_price_id,
            phone: '',
            first_name: 'Fatima',
            last_name: 'Guichet',
            email: '',
            locale: 'fr',
            amount: $amount,
            payment_method: $method,
            amount_collected: $amount,
            idempotency_key: $key ?? Str::uuid()->toString(),
            items: $items,
        ));
    }

    /**
     * @return array{0: Event, 1: int, 2: Product, 3: ProductPrice, 4: Product, 5: ProductPrice}
     */
    private function cartFixture(): array
    {
        [$event, $dayPass, $dayPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $dayPass);
        [$vip, $vipPrice] = $this->addProduct($event, 'VIP', 90.00);

        return [$event, $user->id, $dayPass, $dayPrice, $vip, $vipPrice];
    }

    public function test_a_cart_of_three_tickets_creates_a_single_order(): void
    {
        [$event, $agentId, $dayPass, $dayPrice, $vip, $vipPrice] = $this->cartFixture();

        $result = $this->sell($event, $agentId, [
            new CreateBoxOfficeSaleItemDTO(product_id: $dayPass->id, product_price_id: $dayPrice->id, quantity: 2),
            new CreateBoxOfficeSaleItemDTO(product_id: $vip->id, product_price_id: $vipPrice->id, quantity: 1),
        ], 140.00);

        $orderIds = collect($result->attendees)->map(fn ($attendee) => $attendee->getOrderId())->unique()->values();
        self::assertCount(3, $result->attendees);
        self::assertCount(1, $orderIds);

        $order = DB::table('orders')->find($orderIds[0]);
        self::assertSame(140.0, (float) $order->total_gross);
        self::assertSame('COMPLETED', $order->status);
        self::assertSame('PAYMENT_RECEIVED', $order->payment_status);
        self::assertTrue((bool) $order->is_manually_created);
        self::assertSame($orderIds[0], $result->order->getId());

        $orderItems = DB::table('order_items')->where('order_id', $order->id)->orderBy('price')->get();
        self::assertCount(2, $orderItems);
        self::assertSame(2, (int) $orderItems[0]->quantity);
        self::assertSame(25.0, (float) $orderItems[0]->price);
        self::assertSame(1, (int) $orderItems[1]->quantity);
        self::assertSame(90.0, (float) $orderItems[1]->price);

        self::assertSame(3, DB::table('attendees')->where('order_id', $order->id)->count());
        self::assertSame(
            [$order->id],
            DB::table('box_office_sale_items')->where('box_office_sale_id', $result->saleId)->distinct()->pluck('order_id')->all(),
        );
        self::assertSame($order->id, DB::table('box_office_sales')->where('id', $result->saleId)->value('order_id'));
    }

    public function test_stock_is_decremented_per_price(): void
    {
        [$event, $agentId, $dayPass, $dayPrice, $vip, $vipPrice] = $this->cartFixture();

        $this->sell($event, $agentId, [
            new CreateBoxOfficeSaleItemDTO(product_id: $dayPass->id, product_price_id: $dayPrice->id, quantity: 2),
            new CreateBoxOfficeSaleItemDTO(product_id: $vip->id, product_price_id: $vipPrice->id, quantity: 1),
        ], 140.00);

        self::assertSame(2, ProductPrice::find($dayPrice->id)->quantity_sold);
        self::assertSame(1, ProductPrice::find($vipPrice->id)->quantity_sold);
    }

    public function test_order_status_event_is_fired_once_per_cart(): void
    {
        [$event, $agentId, $dayPass, $dayPrice, $vip, $vipPrice] = $this->cartFixture();
        $dispatched = 0;
        EventFacade::listen(OrderStatusChangedEvent::class, function () use (&$dispatched) {
            $dispatched++;
        });

        $this->sell($event, $agentId, [
            new CreateBoxOfficeSaleItemDTO(product_id: $dayPass->id, product_price_id: $dayPrice->id, quantity: 2),
            new CreateBoxOfficeSaleItemDTO(product_id: $vip->id, product_price_id: $vipPrice->id, quantity: 1),
        ], 140.00);

        self::assertSame(1, $dispatched);
    }

    public function test_free_cart_order_requires_no_payment(): void
    {
        [$event, , , $user] = $this->createEventWithProduct(price: 25.00);
        [$badge, $badgePrice] = $this->addProduct($event, 'Conférence', 0.00);

        $result = $this->sell($event, $user->id, [
            new CreateBoxOfficeSaleItemDTO(product_id: $badge->id, product_price_id: $badgePrice->id, quantity: 2),
        ], 0.00, method: BoxOfficePaymentMethod::FREE);

        $order = DB::table('orders')->find($result->order->getId());
        self::assertSame('NO_PAYMENT_REQUIRED', $order->payment_status);
        self::assertSame(2, DB::table('attendees')->where('order_id', $order->id)->count());
    }

    public function test_idempotent_replay_returns_the_same_single_order(): void
    {
        [$event, $agentId, $dayPass, $dayPrice, $vip, $vipPrice] = $this->cartFixture();
        $items = [
            new CreateBoxOfficeSaleItemDTO(product_id: $dayPass->id, product_price_id: $dayPrice->id, quantity: 2),
            new CreateBoxOfficeSaleItemDTO(product_id: $vip->id, product_price_id: $vipPrice->id, quantity: 1),
        ];
        $key = Str::uuid()->toString();

        $first = $this->sell($event, $agentId, $items, 140.00, $key);
        $replay = $this->sell($event, $agentId, $items, 140.00, $key);

        self::assertSame($first->order->getId(), $replay->order->getId());
        self::assertCount(3, $replay->attendees);
        self::assertSame(1, DB::table('orders')->where('event_id', $event->id)->count());
    }
}
