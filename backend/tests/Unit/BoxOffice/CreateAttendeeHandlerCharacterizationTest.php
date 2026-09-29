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
 * Characterizes the CURRENT behavior of CreateAttendeeHandler — the handler
 * the Box Office slice 1 (Option A) intends to reuse as-is. These tests
 * document what the handler does today, including its known gaps (S1: no
 * server-side price validation). They are not testing desired Kiosk
 * behavior — that lives in CreateBoxOfficeSaleHandler (not yet written).
 */
class CreateAttendeeHandlerCharacterizationTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    public function test_manual_sale_zero_amount_creates_no_payment_required_order(): void
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
            amount_paid: 0.0,
            locale: 'en',
            product_price_id: $productPrice->id,
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
            amount_paid: 25.00,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        $order = Order::find($attendee->getOrderId());

        self::assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $order->payment_status);
        self::assertSame(25.0, (float) $order->total_gross);
    }

    /**
     * Documents S1 (PICHA_BOX_OFFICE_SECURITY_FINDINGS.md): the handler never
     * compares amount_paid against product_prices.price. Whatever the caller
     * sends is written verbatim to Order.total_gross / OrderItem.price, even
     * though the product's real price (25.00) is completely different.
     *
     * This assertion documents the DESIRED behavior (server charges its own
     * known price, ignoring/validating the client-sent amount) and is
     * expected to be RED today, since no such validation exists yet — the
     * failure itself is the proof of S1.
     */
    public function test_manual_sale_amount_is_taken_verbatim_from_client(): void
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
            amount_paid: 1.00, // far below the real price of 25.00 — accepted anyway
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        $order = Order::find($attendee->getOrderId());

        self::assertSame(
            25.0,
            (float) $order->total_gross,
            'S1: amount_paid must be validated against product_prices.price, not trusted verbatim from the client',
        );
    }

    public function test_manual_sale_negative_amount_is_rejected_by_validation(): void
    {
        $rules = (new CreateAttendeeRequest)->rules();

        $validator = Validator::make([
            'product_id' => 1,
            'product_price_id' => 1,
            'email' => 'jane@example.test',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'amount_paid' => -5.00,
            'send_confirmation_email' => false,
            'locale' => 'en',
        ], $rules);

        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('amount_paid'));
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
            amount_paid: 50.00,
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
            amount_paid: 25.00,
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
            amount_paid: 25.00,
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
            amount_paid: 25.00,
            locale: 'en',
            product_price_id: $productPrice->id,
        ));

        self::assertInstanceOf(OrderStatusChangedEvent::class, $captured);
        self::assertSame($attendee->getOrderId(), $captured->order->getId());
        self::assertFalse($captured->sendEmails);
    }
}
