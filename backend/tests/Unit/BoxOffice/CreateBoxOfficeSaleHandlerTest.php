<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\DomainObjects\Status\BoxOfficeSaleStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Exceptions\BoxOfficePriceMismatchException;
use HiEvents\Exceptions\ProductNotScannableException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Models\Order;
use HiEvents\Models\ProductPrice;
use HiEvents\Jobs\Order\SendOrderDetailsEmailJob;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeSaleHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleDTO;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * TDD (red) tests for the NOT-YET-IMPLEMENTED CreateBoxOfficeSaleHandler
 * (Option A — PICHA_BOX_OFFICE_DESIGN_OPTIONS.md). None of the referenced
 * classes (CreateBoxOfficeSaleHandler, CreateBoxOfficeSaleDTO,
 * BoxOfficePaymentMethod, BoxOfficePriceMismatchException,
 * ProductNotScannableException, the box_office_sales table) exist yet —
 * every test below is expected to error ("Class not found" / "relation does
 * not exist") until the slice 1 implementation lands. This is intentional:
 * see FIRST_SLICE §5.2 ("rouges tant que non implémenté — TDD").
 *
 * AC references are to PICHA_BOX_OFFICE_FIRST_SLICE.md §4.
 */
class CreateBoxOfficeSaleHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use BoxOfficeTestFixtures;

    private function makeDto(
        int    $eventId,
        int    $agentUserId,
        int    $productId,
        int    $productPriceId,
        float  $amount,
        float  $amountCollected,
        BoxOfficePaymentMethod $paymentMethod = BoxOfficePaymentMethod::CASH,
        ?string $idempotencyKey = null,
        bool $sendConfirmationEmail = false,
    ): CreateBoxOfficeSaleDTO
    {
        return new CreateBoxOfficeSaleDTO(
            event_id: $eventId,
            agent_user_id: $agentUserId,
            product_id: $productId,
            product_price_id: $productPriceId,
            phone: '+33612345678',
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane@example.test',
            locale: 'en',
            amount: $amount,
            payment_method: $paymentMethod,
            amount_collected: $amountCollected,
            idempotency_key: $idempotencyKey ?? \Illuminate\Support\Str::uuid()->toString(),
            send_confirmation_email: $sendConfirmationEmail,
        );
    }

    public function test_phone_only_creates_placeholder_attendee_identity(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $user->id,
            product_id: $product->id,
            product_price_id: $productPrice->id,
            phone: '06 12 34 56 78',
            first_name: '',
            last_name: '',
            email: '',
            locale: 'fr',
            amount: 25.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00,
            idempotency_key: \Illuminate\Support\Str::uuid()->toString(),
        ));

        self::assertSame('', $result->attendee->getFirstName());
        self::assertSame('', $result->attendee->getLastName());
        self::assertNull($result->attendee->getEmail());
        self::assertSame('+33612345678', DB::table('box_office_sales')->where('id', $result->saleId)->value('phone'));
    }

    public function test_sale_without_phone_stores_null_email(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);
        $key = \Illuminate\Support\Str::uuid()->toString();

        $result = $handler->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $user->id,
            product_id: $product->id,
            product_price_id: $productPrice->id,
            phone: '',
            first_name: 'Jane',
            last_name: '',
            email: null,
            locale: 'fr',
            amount: 25.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00,
            idempotency_key: $key,
        ));

        self::assertNull(DB::table('box_office_sales')->where('id', $result->saleId)->value('phone'));
        self::assertNull($result->attendee->getEmail());
    }

    /** AC-2 */
    public function test_price_is_resolved_server_side_and_client_amount_ignored(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 25.00,
        ));

        $order = Order::find($result->order->getId());

        self::assertSame(25.0, (float)$order->total_gross);
    }

    /** AC-2 — strict tolerance: any mismatch is a 422-equivalent rejection */
    public function test_price_mismatch_beyond_tolerance_is_rejected(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $this->expectException(BoxOfficePriceMismatchException::class);

        try {
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $user->id,
                productId: $product->id,
                productPriceId: $productPrice->id,
                amount: 1.00, // real price is 25.00
                amountCollected: 1.00,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /** AC-4 */
    public function test_free_payment_method_sets_no_payment_required(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 0.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 0.00,
            amountCollected: 0.00,
            paymentMethod: BoxOfficePaymentMethod::FREE,
        ));

        $order = Order::find($result->order->getId());

        self::assertSame(OrderPaymentStatus::NO_PAYMENT_REQUIRED->name, $order->payment_status);
    }

    /** AC-3 — amount_collected is a distinct audit field, not constrained to equal the price */
    public function test_amount_collected_stored_separately_from_price(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 30.00, // e.g. cash tendered, change given — distinct from the price
        ));

        $sale = DB::table('box_office_sales')->where('id', $result->saleId)->first();

        self::assertSame(30.0, (float)$sale->amount_collected);
        self::assertSame(25.0, (float)Order::find($result->order->getId())->total_gross);
    }

    /** AC-13 — D11: product not attached to any active check-in list must be blocked */
    public function test_product_without_active_checkin_list_is_rejected(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        // Deliberately NOT attached to any check-in list.

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $this->expectException(ProductNotScannableException::class);

        try {
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $user->id,
                productId: $product->id,
                productPriceId: $productPrice->id,
                amount: 25.00,
                amountCollected: 25.00,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /** AC-9 */
    public function test_out_of_stock_returns_conflict(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 3,
            quantitySold: 3,
        );
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $this->expectException(ResourceConflictException::class);

        try {
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $user->id,
                productId: $product->id,
                productPriceId: $productPrice->id,
                amount: 25.00,
                amountCollected: 25.00,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /** AC-5, AC-26 */
    public function test_successful_sale_creates_order_attendee_and_box_office_sale_row(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 25.00,
        ));

        self::assertNotNull($result->attendee->getPublicId());
        self::assertSame('ACTIVE', $result->attendee->getStatus());
        self::assertSame($productPrice->id, $result->attendee->getProductPriceId());

        $sale = DB::table('box_office_sales')->where('id', $result->saleId)->first();
        self::assertNotNull($sale);
        self::assertSame(BoxOfficeSaleStatus::COMPLETED->name, $sale->status);
        self::assertSame($result->order->getId(), $sale->order_id);
        self::assertSame($result->attendee->getId(), $sale->attendee_id);
    }

    /** AC-6 */
    public function test_quantity_sold_incremented_exactly_once(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 25.00,
        ));

        self::assertSame(1, ProductPrice::find($productPrice->id)->quantity_sold);
    }

    /** AC-7 — slice 1 always forces send_confirmation_email = false */
    public function test_no_email_sent_when_send_confirmation_email_false(): void
    {
        Mail::fake();

        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 25.00,
        ));

        Mail::assertNothingSent();
    }

    public function test_confirmation_email_queued_when_requested_with_email(): void
    {
        Queue::fake();

        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            amount: 25.00,
            amountCollected: 25.00,
            sendConfirmationEmail: true,
        ));

        Queue::assertPushed(SendOrderDetailsEmailJob::class);
    }

    public function test_confirmation_email_not_queued_without_attendee_email(): void
    {
        Queue::fake();

        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $user->id,
            product_id: $product->id,
            product_price_id: $productPrice->id,
            phone: '0612345678',
            first_name: '',
            last_name: '',
            email: '',
            locale: 'fr',
            amount: 25.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 25.00,
            idempotency_key: \Illuminate\Support\Str::uuid()->toString(),
            send_confirmation_email: true,
        ));

        Queue::assertNotPushed(SendOrderDetailsEmailJob::class);
    }

    /** AC-26 — a downstream failure must not leave a COMPLETED box_office_sales row */
    public function test_partial_failure_rolls_back_box_office_sale(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $otherProduct = \HiEvents\Models\Product::create([
            'event_id' => $event->id,
            'title' => 'Other Ticket',
            'product_type' => \HiEvents\DomainObjects\Enums\ProductType::TICKET->name,
            'type' => 'PAID',
            'order' => 2,
        ]);
        $foreignPrice = \HiEvents\Models\ProductPrice::create([
            'product_id' => $otherProduct->id,
            'price' => 50.00,
            'initial_quantity_available' => 10,
            'quantity_sold' => 0,
            'order' => 1,
        ]);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        try {
            // product_price_id belongs to a different product than product_id —
            // CreateAttendeeHandler::getProductPriceId() rejects this internally,
            // after CreateBoxOfficeSaleHandler has already inserted its own
            // box_office_sales(PENDING) row.
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $user->id,
                productId: $product->id,
                productPriceId: $foreignPrice->id,
                amount: 25.00,
                amountCollected: 25.00,
            ));
            self::fail('expected an exception for a foreign product_price_id');
        } catch (\Throwable) {
            // any failure is acceptable here — what matters is the DB state below
        }

        self::assertSame(
            0,
            DB::table('box_office_sales')
                ->where('event_id', $event->id)
                ->where('status', BoxOfficeSaleStatus::COMPLETED->name)
                ->count(),
            'AC-26: no COMPLETED box_office_sales row must survive a partial failure',
        );
        self::assertSame(
            0,
            DB::table('box_office_sales')->where('event_id', $event->id)->count(),
            'AC-26: a failed sale must not leave a box_office_sales row',
        );
        self::assertSame(0, ProductPrice::find($productPrice->id)->quantity_sold);
    }

    /** D21 — FREE only reflects a product whose price is already 0 */
    public function test_free_payment_method_rejected_when_server_price_is_not_zero(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $this->expectException(BoxOfficePriceMismatchException::class);

        try {
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $user->id,
                productId: $product->id,
                productPriceId: $productPrice->id,
                amount: 25.00, // matches the real (non-zero) price
                amountCollected: 0.00,
                paymentMethod: BoxOfficePaymentMethod::FREE,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /**
     * A QueryException that is NOT a unique_violation (e.g. a genuine bug —
     * a NOT NULL violation from bad caller data) must propagate as-is, not
     * be misread as an idempotency-key race.
     */
    public function test_non_unique_violation_query_exception_is_not_swallowed(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        // agent_user_id is NOT NULL — an id that doesn't exist violates the
        // foreign key (23503), a different SQLSTATE than unique_violation
        // (23505), and must not be caught as an idempotency conflict.
        $bogusAgentId = 999999999;

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $this->expectException(\Illuminate\Database\QueryException::class);

        try {
            $handler->handle($this->makeDto(
                eventId: $event->id,
                agentUserId: $bogusAgentId,
                productId: $product->id,
                productPriceId: $productPrice->id,
                amount: 25.00,
                amountCollected: 25.00,
            ));
        } finally {
            self::assertSame(0, DB::table('box_office_sales')->where('product_price_id', $productPrice->id)->count());
        }
    }

    /**
     * box_office_sales.order_id must be unique (nullable — multiple PENDING
     * rows may still be order_id = null): a completed sale never shares its
     * order with another sale.
     */
    public function test_box_office_sales_order_id_has_unique_index(): void
    {
        $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'box_office_sales'");

        $hasUniqueOrderIdIndex = collect($indexes)->contains(
            fn($index) => str_contains($index->indexdef, 'UNIQUE') && str_contains($index->indexdef, 'order_id')
        );

        self::assertTrue($hasUniqueOrderIdIndex, 'box_office_sales.order_id must have a unique index');
    }

    /**
     * Defense in depth (S1): the server-read price is forwarded to
     * CreateAttendeeHandler as amount_paid, not the raw client amount —
     * even in the edge case where number_format() rounds the client value
     * to the same 2-decimal string as the server price, letting it past
     * validatePrice()'s equality check.
     */
    public function test_server_price_not_client_amount_is_forwarded_to_create_attendee_handler(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(price: 25.00);
        $this->attachCheckInList($event, $product);

        $realHandler = app(CreateAttendeeHandler::class);
        $capturedAmountPaid = null;

        $this->mock(CreateAttendeeHandler::class, function ($mock) use (&$capturedAmountPaid, $realHandler) {
            $mock->shouldReceive('handle')
                ->once()
                ->andReturnUsing(function (CreateAttendeeDTO $dto) use (&$capturedAmountPaid, $realHandler) {
                    $capturedAmountPaid = $dto->amount_paid;

                    return $realHandler->handle($dto);
                });
        });

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $handler->handle($this->makeDto(
            eventId: $event->id,
            agentUserId: $user->id,
            productId: $product->id,
            productPriceId: $productPrice->id,
            // Rounds to "25.00" via number_format() — passes validatePrice()
            // — but is not bit-identical to the server's 25.00.
            amount: 25.004,
            amountCollected: 25.004,
        ));

        self::assertSame(25.0, $capturedAmountPaid);
    }

    public function test_cart_items_create_one_sale_and_n_attendees(): void
    {
        [$event, $product, $productPrice, $user] = $this->createEventWithProduct(
            price: 25.00,
            initialQuantityAvailable: 10,
        );
        $this->attachCheckInList($event, $product);

        $handler = app(CreateBoxOfficeSaleHandler::class);

        $result = $handler->handle(new CreateBoxOfficeSaleDTO(
            event_id: $event->id,
            agent_user_id: $user->id,
            product_id: $product->id,
            product_price_id: $productPrice->id,
            phone: '',
            first_name: '',
            last_name: '',
            email: '',
            locale: 'fr',
            amount: 50.00,
            payment_method: BoxOfficePaymentMethod::CASH,
            amount_collected: 50.00,
            idempotency_key: \Illuminate\Support\Str::uuid()->toString(),
            items: [
                new \HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO(
                    product_id: $product->id,
                    product_price_id: $productPrice->id,
                    quantity: 2,
                ),
            ],
        ));

        self::assertCount(2, $result->attendees);
        self::assertSame(2, ProductPrice::find($productPrice->id)->quantity_sold);
        self::assertSame(2, DB::table('box_office_sale_items')->where('box_office_sale_id', $result->saleId)->count());
    }
}
