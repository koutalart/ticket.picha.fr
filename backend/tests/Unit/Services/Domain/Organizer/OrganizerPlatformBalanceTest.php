<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Organizer;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Order;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceDeductionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPlatformBalanceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderOrganizerShareService;
use HiEvents\Services\Domain\Organizer\PlatformBalance\OrganizerPlatformBalanceService;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentFailedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripeRefundExpiredOrderService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\Support\BoxOfficeTestFixtures;
use Tests\TestCase;

/**
 * PICHA (04/11): amounts owed by an organizer (protections paid out before the change) are withheld on the
 * organizer part of their next Stripe Connect payments.
 * Typical order: 1 ticket 20 € + platform fee 0,99 € + protection 0,49 € = 21,48 €, organizer part 20 €.
 */
class OrganizerPlatformBalanceTest extends TestCase
{
    use BoxOfficeTestFixtures;
    use DatabaseTransactions;

    private const ORGANIZER_ID = 4;

    public function test_withholding_is_capped_to_the_remaining_balance(): void
    {
        $withheld = $this->calculationService(remaining: 5.00)->calculateWithholding($this->typicalOrder());

        self::assertSame(500, $withheld->toMinorUnit());
    }

    public function test_withholding_is_capped_to_the_organizer_part_of_the_order(): void
    {
        $withheld = $this->calculationService(remaining: 57.88)->calculateWithholding($this->typicalOrder());

        self::assertSame(2000, $withheld->toMinorUnit());
    }

    public function test_no_withholding_without_balance(): void
    {
        $withheld = $this->calculationService(remaining: 0.0)->calculateWithholding($this->typicalOrder());

        self::assertSame(0, $withheld->toMinorUnit());
    }

    public function test_succeeded_payment_lowers_the_balance_once(): void
    {
        [$order, $organizerId] = $this->paidOrder();
        $service = app(OrganizerPlatformBalanceService::class);
        $balance = $service->addBalance($organizerId, 57.88, 'USD', 'Options sérénité versées avant le 04/11', 'F-TEST-1');
        $stripePayment = $this->stripePayment($order, withheldMinor: 2000);

        self::assertEqualsWithDelta(20.00, $service->recordWithholding($stripePayment, $order), 0.001);
        self::assertEqualsWithDelta(0.0, $service->recordWithholding($stripePayment, $order), 0.001);

        self::assertEqualsWithDelta(37.88, (float) DB::table('organizer_platform_balances')->where('id', $balance->getId())->value('remaining_amount'), 0.001);
        self::assertSame(1, DB::table('organizer_platform_balance_deductions')->where('order_id', $order->getId())->count());
        self::assertEqualsWithDelta(20.00, (float) DB::table('organizer_platform_balance_deductions')->where('order_id', $order->getId())->value('amount'), 0.001);
    }

    public function test_balance_never_goes_below_zero_and_is_marked_settled(): void
    {
        [$order, $organizerId] = $this->paidOrder();
        $service = app(OrganizerPlatformBalanceService::class);
        $balance = $service->addBalance($organizerId, 15.68, 'USD', 'Options sérénité versées avant le 04/11');

        self::assertEqualsWithDelta(15.68, $service->recordWithholding($this->stripePayment($order, withheldMinor: 2000), $order), 0.001);

        $row = DB::table('organizer_platform_balances')->where('id', $balance->getId())->first();
        self::assertEqualsWithDelta(0.0, (float) $row->remaining_amount, 0.001);
        self::assertNotNull($row->settled_at);
    }

    /**
     * The organizer paid the withheld amount out of their own pocket when the buyer was refunded:
     * a withholding is never put back in the balance.
     */
    public function test_refunding_the_order_never_puts_the_withholding_back(): void
    {
        [$order, $organizerId] = $this->paidOrder();
        $service = app(OrganizerPlatformBalanceService::class);
        $balance = $service->addBalance($organizerId, 57.88, 'USD', 'Options sérénité versées avant le 04/11');
        $service->recordWithholding($this->stripePayment($order, withheldMinor: 2000), $order);

        DB::table('event_statistics')->updateOrInsert(['event_id' => $order->getEventId()], [
            'sales_total_gross' => 20.99, 'sales_total_organizer' => 20.00, 'products_sold' => 1, 'orders_created' => 1,
            'attendees_registered' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ChargeRefundUpdatedHandler::class)->handleEvent(Refund::constructFrom([
            'id' => 're_balance_'.$order->getId(),
            'payment_intent' => 'pi_balance_'.$order->getId(),
            'amount' => 2000,
            'status' => 'succeeded',
            'metadata' => ['picha_source' => 'dashboard'],
        ]));

        self::assertSame(1, DB::table('order_refunds')->where('order_id', $order->getId())->count());
        self::assertEqualsWithDelta(37.88, (float) DB::table('organizer_platform_balances')->where('id', $balance->getId())->value('remaining_amount'), 0.001);
        self::assertSame(1, DB::table('organizer_platform_balance_deductions')->where('order_id', $order->getId())->count());
    }

    public function test_payment_without_withholding_records_nothing(): void
    {
        [$order, $organizerId] = $this->paidOrder();
        $service = app(OrganizerPlatformBalanceService::class);
        $balance = $service->addBalance($organizerId, 57.88, 'USD', 'Test');

        self::assertEqualsWithDelta(0.0, $service->recordWithholding($this->stripePayment($order, withheldMinor: 0), $order), 0.001);
        self::assertEqualsWithDelta(57.88, (float) DB::table('organizer_platform_balances')->where('id', $balance->getId())->value('remaining_amount'), 0.001);
    }

    public function test_failed_payment_withholds_nothing(): void
    {
        [$order, $organizerId] = $this->paidOrder(paymentStatus: 'AWAITING_PAYMENT', status: 'RESERVED');
        $balance = app(OrganizerPlatformBalanceService::class)->addBalance($organizerId, 57.88, 'USD', 'Test');
        $this->stripePayment($order, withheldMinor: 2000);
        Event::fake();

        app(PaymentIntentFailedHandler::class)->handleEvent(PaymentIntent::constructFrom([
            'id' => 'pi_balance_'.$order->getId(),
            'amount_received' => 0,
        ]));

        self::assertEqualsWithDelta(57.88, (float) DB::table('organizer_platform_balances')->where('id', $balance->getId())->value('remaining_amount'), 0.001);
        self::assertSame(0, DB::table('organizer_platform_balance_deductions')->where('order_id', $order->getId())->count());
    }

    public function test_succeeded_payment_handler_records_the_withholding(): void
    {
        Event::fake();
        $order = $this->typicalOrder()
            ->setPaymentStatus('AWAITING_PAYMENT')
            ->setReservedUntil(Carbon::now()->addHour()->toDateTimeString());
        $stripePayment = (new StripePaymentDomainObject())->setId(7)->setOrderId($order->getId())->setPlatformBalanceWithheld(500);
        $stripePayment->setOrder($order);

        $stripePayments = m::mock(StripePaymentsRepository::class);
        $stripePayments->shouldReceive('loadRelation')->andReturnSelf();
        $stripePayments->shouldReceive('findFirstWhere')->andReturn($stripePayment);
        $stripePayments->shouldReceive('updateWhere');

        $orders = m::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('loadRelation')->andReturnSelf();
        $orders->shouldReceive('updateFromArray')->andReturn($order);

        $attendees = m::mock(AttendeeRepositoryInterface::class);
        $attendees->shouldReceive('updateWhere');
        $quantities = m::mock(ProductQuantityUpdateService::class);
        $quantities->shouldReceive('updateQuantitiesFromOrder');
        $settings = m::mock(EventSettingsRepositoryInterface::class);
        $settings->shouldReceive('findFirstWhere')->andReturn((new EventSettingDomainObject())->setEnableInvoicing(false));
        $cache = m::mock(CacheRepository::class);
        $cache->shouldReceive('has')->andReturnFalse();
        $cache->shouldReceive('put');
        $dispatcher = m::mock(DomainEventDispatcherService::class);
        $dispatcher->shouldReceive('dispatch');
        $applicationFees = m::mock(OrderApplicationFeeService::class);
        $applicationFees->shouldReceive('createOrderApplicationFee');
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn(callable $callback) => $callback());
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        $balanceService = m::mock(OrganizerPlatformBalanceService::class);
        $balanceService->shouldReceive('recordWithholding')->once()->with($stripePayment, $order)->andReturn(5.00);

        (new PaymentIntentSucceededHandler(
            $orders,
            $stripePayments,
            m::mock(AffiliateRepositoryInterface::class),
            $quantities,
            app(StripeRefundExpiredOrderService::class),
            $attendees,
            $database,
            $logger,
            $cache,
            $dispatcher,
            $applicationFees,
            $settings,
            $balanceService,
        ))->handleEvent(PaymentIntent::constructFrom(['id' => 'pi_ok', 'amount_received' => 2148, 'application_fee_amount' => 198]));
    }

    public function test_command_dry_run_records_nothing(): void
    {
        [, $organizerId] = $this->paidOrder();

        $exitCode = Artisan::call('platform:balance-add', [
            'organizer' => $organizerId,
            'amount' => '57.88',
            '--reason' => 'Options sérénité versées avant le 04/11',
            '--currency' => 'USD',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(0, DB::table('organizer_platform_balances')->where('organizer_id', $organizerId)->count());
    }

    public function test_command_refuses_a_wrong_organizer_name(): void
    {
        [, $organizerId] = $this->paidOrder();

        $exitCode = Artisan::call('platform:balance-add', [
            'organizer' => $organizerId,
            'amount' => '57.88',
            '--reason' => 'Test',
            '--name-contains' => 'Innocent',
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame(0, DB::table('organizer_platform_balances')->where('organizer_id', $organizerId)->count());
    }

    private function calculationService(float $remaining): OrganizerPlatformBalanceService
    {
        $balances = m::mock(OrganizerPlatformBalanceRepositoryInterface::class);
        $balances->shouldReceive('getRemainingTotal')->with(self::ORGANIZER_ID, 'EUR')->andReturn($remaining);

        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findWhereIn')->andReturn(new Collection([
            (new ProductDomainObject())->setId(1)->setProductType('TICKET'),
            (new ProductDomainObject())->setId(2)->setProductType('GENERAL')->setIsRefundProtection(true),
        ]));

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        return new OrganizerPlatformBalanceService(
            $balances,
            m::mock(OrganizerPlatformBalanceDeductionRepositoryInterface::class),
            new OrderOrganizerShareService($products, m::mock(OrderRepositoryInterface::class)),
            m::mock(EventRepositoryInterface::class),
            $logger,
        );
    }

    private function typicalOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(900)
            ->setEventId(15)
            ->setCurrency('EUR')
            ->setTotalFee(0.99)
            ->setTotalGross(21.48)
            ->setTotalRefunded(0.0)
            ->setEvent((new EventDomainObject())->setId(15)->setOrganizerId(self::ORGANIZER_ID))
            ->setOrderItems(new Collection([
                (new OrderItemDomainObject())->setProductId(1)->setQuantity(1)->setPrice(20.00)->setTotalBeforeAdditions(20.00)->setTotalServiceFee(0.99)->setTotalGross(20.99),
                (new OrderItemDomainObject())->setProductId(2)->setQuantity(1)->setPrice(0.49)->setTotalBeforeAdditions(0.49)->setTotalServiceFee(0.0)->setTotalGross(0.49),
            ]));
    }

    /**
     * @return array{0: OrderDomainObject, 1: int}
     */
    private function paidOrder(string $paymentStatus = 'PAYMENT_RECEIVED', string $status = 'COMPLETED'): array
    {
        [$event] = $this->createEventWithProduct(price: 20.00);

        $order = Order::create([
            'event_id' => $event->id,
            'total_gross' => 20.99,
            'total_fee' => 0.99,
            'total_refunded' => 0,
            'currency' => 'USD',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'email' => 'acheteur@example.test',
            'first_name' => 'Anli',
            'last_name' => 'Madi',
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
        ]);

        return [app(OrderRepositoryInterface::class)->findById($order->id), (int) $event->organizer_id];
    }

    private function stripePayment(OrderDomainObject $order, int $withheldMinor): StripePaymentDomainObject
    {
        $id = DB::table('stripe_payments')->insertGetId([
            'order_id' => $order->getId(),
            'payment_intent_id' => 'pi_balance_'.$order->getId(),
            'connected_account_id' => 'acct_test',
            'application_fee_gross' => 99,
            'platform_balance_withheld' => $withheldMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(StripePaymentsRepository::class)->findById($id);
    }
}
