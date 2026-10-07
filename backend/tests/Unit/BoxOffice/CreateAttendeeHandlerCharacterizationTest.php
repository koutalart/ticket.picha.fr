<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\InvalidProductPriceId;
use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Http\Request\Attendee\CreateAttendeeRequest;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * Behavior of CreateAttendeeHandler (manual "Add attendee" from the organizer
 * dashboard). The price always comes from product_prices — never from the
 * client (fixes S1); the only client choice is the "free" checkbox.
 */
class CreateAttendeeHandlerCharacterizationTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_manual_sale_marked_free_creates_no_payment_required_order(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        $handler = app(CreateAttendeeHandler::class);

        $attendee = $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
            is_free: true,
        ));

        $order = Order::find($attendee->getOrderId());

        self::assertSame(OrderPaymentStatus::NO_PAYMENT_REQUIRED->name, $order->payment_status);
        self::assertSame(0.0, (float) $order->total_gross);
    }

    public function test_manual_sale_paid_amount_sets_payment_received(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        $handler = app(CreateAttendeeHandler::class);

        $attendee = $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        $order = Order::find($attendee->getOrderId());

        self::assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $order->payment_status);
        self::assertSame(25.0, (float) $order->total_gross);
    }

    public function test_manual_sale_is_charged_the_server_price(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        $handler = app(CreateAttendeeHandler::class);

        $attendee = $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        $order = Order::find($attendee->getOrderId());

        self::assertSame(25.0, (float) $order->total_gross, 'S1: the price comes from product_prices, not from the client');
        self::assertSame(25.0, (float) $order->order_items()->first()->price);
    }

    public function test_client_cannot_send_an_amount(): void
    {
        $rules = (new CreateAttendeeRequest)->rules();

        self::assertArrayNotHasKey('amount_paid', $rules, 'S1: no client-entered amount is accepted');
        self::assertArrayNotHasKey('taxes_and_fees', $rules, 'taxes and fees are computed server-side');

        $validator = Validator::make([
            'product_id' => 1,
            'product_price_id' => 1,
            'email' => 'jane@example.test',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'is_free' => 'not-a-boolean',
            'send_confirmation_email' => false,
            'locale' => 'en',
        ], $rules);

        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('is_free'));
    }

    public function test_manual_sale_product_price_id_from_other_product_is_rejected(): void
    {
        [$event, $productA] = $this->createEventWithProduct(price: 25.00);

        $productB = Product::create([
            'event_id' => $event->id,
            'title' => 'Other Ticket',
            'product_type' => \HiEvents\DomainObjects\Enums\ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 2,
        ]);

        $productPriceB = ProductPrice::create([
            'product_id' => $productB->id,
            'price' => 50.00,
            'initial_quantity_available' => 10,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        $handler = app(CreateAttendeeHandler::class);

        $this->expectException(InvalidProductPriceId::class);

        $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $productA->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPriceB->id, // belongs to productB, not productA
        ));
    }

    public function test_manual_sale_out_of_stock_throws_no_tickets_available(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 5,
            quantitySold: 5, // sold out
        );

        $handler = app(CreateAttendeeHandler::class);

        $this->expectException(NoTicketsAvailableException::class);

        $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));
    }

    public function test_manual_sale_increments_quantity_sold_by_one(): void
    {
        [$event, $product, $productPrice] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 100,
            quantitySold: 0,
        );

        $handler = app(CreateAttendeeHandler::class);

        $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        self::assertSame(1, ProductPrice::find($productPrice->id)->quantity_sold);
    }

    public function test_manual_sale_emits_order_status_changed_with_send_emails_flag(): void
    {
        // Event::fake() swaps the 'events' binding for an EventFake, which
        // does not satisfy DomainEventDispatcherService's constructor
        // type-hint (concrete Illuminate\Events\Dispatcher, not the
        // contract interface) — CreateAttendeeHandler::queueWebhooks() uses
        // that service and would fatal with a TypeError. A plain listener
        // on the real dispatcher captures the event without swapping it.
        $captured = null;
        Event::listen(OrderStatusChangedEvent::class, function (OrderStatusChangedEvent $e) use (&$captured) {
            $captured = $e;
        });

        [$event, $product, $productPrice] = $this->createEventWithProduct(price: 25.00);

        $handler = app(CreateAttendeeHandler::class);

        $attendee = $handler->handle(new CreateAttendeeDTO(
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            product_id: $product->id,
            event_id: $event->id,
            send_confirmation_email: false,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        self::assertInstanceOf(OrderStatusChangedEvent::class, $captured);
        self::assertSame($attendee->getOrderId(), $captured->order->getId());
        self::assertFalse($captured->sendEmails);
    }
}
