<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountVatSettingDomainObject;
use HiEvents\DomainObjects\Enums\RefundReason;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventDailyStatisticRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventStatisticRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\PartialEditAttendeeHandler;
use HiEvents\Services\Application\Handlers\Order\CancelOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CancelOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsIncrementService;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Order\OrderOrganizerShareService;
use HiEvents\Services\Domain\Order\Vat\VatRateDeterminationService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Domain\Payment\Stripe\StripeRefundExpiredOrderService;
use HiEvents\Services\Infrastructure\CurrencyConversion\CurrencyConversionClientInterface;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Services\Infrastructure\Utlitiy\Retry\Retrier;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Service\RefundService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * PICHA (04/11): the cancellation protection is a platform revenue, like the platform fee.
 * Typical order: 1 ticket 20 € + platform fee 0,99 € + protection 0,49 € = 21,48 € paid by the buyer.
 * - PICHA (application fee): 0,99 + 0,49 = 1,48 €
 * - organizer: 20,00 €
 * A refund only gives back the ticket price.
 */
class RefundProtectionPlatformRevenueTest extends TestCase
{
    private const EVENT_ID = 15;
    private const ORDER_ID = 900;
    private const TICKET_ID = 32;
    private const PROTECTION_ID = 33;

    public function test_application_fee_is_the_platform_fee_plus_the_protection(): void
    {
        $fee = $this->feeService()->calculateApplicationFee($this->accountConfiguration(), $this->orderWithProtection());

        self::assertSame(148, $fee->grossApplicationFee->toMinorUnit());
        self::assertSame(148, $fee->netApplicationFee->toMinorUnit());
        self::assertSame(49, $fee->refundProtectionAmount->toMinorUnit());
        self::assertNull($fee->applicationFeeVatAmount);
    }

    public function test_order_without_protection_keeps_the_platform_fee_only(): void
    {
        $fee = $this->feeService()->calculateApplicationFee($this->accountConfiguration(), $this->orderWithoutProtection());

        self::assertSame(99, $fee->grossApplicationFee->toMinorUnit());
        self::assertSame(99, $fee->netApplicationFee->toMinorUnit());
        self::assertSame(0, $fee->refundProtectionAmount->toMinorUnit());
    }

    public function test_vat_of_the_commission_is_computed_on_the_platform_fee_only(): void
    {
        $vatRates = m::mock(VatRateDeterminationService::class);
        $vatRates->shouldReceive('determineVatRatePercentage')->andReturn(0.20);

        $fee = $this->feeService($vatRates)->calculateApplicationFee(
            $this->accountConfiguration(),
            $this->orderWithProtection(),
            new AccountVatSettingDomainObject(),
        );

        self::assertSame(20, $fee->applicationFeeVatAmount->toMinorUnit());
        self::assertSame(99 + 49, $fee->netApplicationFee->toMinorUnit());
        self::assertSame(99 + 20 + 49, $fee->grossApplicationFee->toMinorUnit());
        self::assertSame(
            $fee->netApplicationFee->toMinorUnit() + $fee->applicationFeeVatAmount->toMinorUnit(),
            $fee->grossApplicationFee->toMinorUnit(),
        );
    }

    public function test_organizer_share_excludes_the_fees_and_the_protection(): void
    {
        $share = $this->shareService()->getShare($this->orderWithProtection());

        self::assertEqualsWithDelta(21.48, $share->totalGross, 0.001);
        self::assertEqualsWithDelta(0.99, $share->serviceFees, 0.001);
        self::assertEqualsWithDelta(0.49, $share->refundProtection, 0.001);
        self::assertEqualsWithDelta(20.00, $share->organizerSales, 0.001);
        self::assertEqualsWithDelta(20.00, $share->refundableToBuyer, 0.001);

        $refunded = $this->shareService()->getShare($this->orderWithProtection()->setTotalRefunded(20.00));
        self::assertEqualsWithDelta(0.0, $refunded->refundableToBuyer, 0.001);
    }

    public function test_organizer_share_of_an_order_without_protection(): void
    {
        $share = $this->shareService()->getShare($this->orderWithoutProtection());

        self::assertEqualsWithDelta(0.0, $share->refundProtection, 0.001);
        self::assertEqualsWithDelta(20.00, $share->organizerSales, 0.001);
    }

    public function test_organizer_part_of_a_refund_never_exceeds_the_ticket_price(): void
    {
        $order = $this->orderWithProtection();

        self::assertEqualsWithDelta(20.00, $this->shareService()->getOrganizerPartOfRefund($order, 21.48, 0.0), 0.001);
        self::assertEqualsWithDelta(10.00, $this->shareService()->getOrganizerPartOfRefund($order, 10.00, 0.0), 0.001);
        self::assertEqualsWithDelta(5.00, $this->shareService()->getOrganizerPartOfRefund($order, 10.00, 15.00), 0.001);
    }

    public function test_refund_above_the_ticket_price_is_refused_and_nothing_is_sent_to_stripe(): void
    {
        $refundService = m::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldNotReceive('refundPayment');

        $this->expectException(RefundNotPossibleException::class);

        $this->refundHandler($refundService, $this->orderRepositoryFor($this->orderWithProtection()))->handle($this->refundDto(21.48));
    }

    public function test_refund_of_the_ticket_keeps_the_fees_and_the_protection_with_the_platform(): void
    {
        $refundService = m::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('refundPayment')
            ->once()
            ->withArgs(function (...$args) {
                $amount = $args['amount'] ?? $args[0];
                $refundApplicationFee = $args['refundApplicationFee'] ?? $args[4] ?? false;

                return $amount->toMinorUnit() === 2000 && $refundApplicationFee === false;
            })
            ->andReturn(Refund::constructFrom(['id' => 're_1']));

        $orderRepository = $this->orderRepositoryFor($this->orderWithProtection());
        $orderRepository->shouldReceive('updateFromArray')->once()->andReturn($this->orderWithProtection());

        $this->refundHandler($refundService, $orderRepository)->handle($this->refundDto(20.00));
    }

    public function test_cancelling_with_refund_refunds_the_ticket_price_only(): void
    {
        $order = $this->orderWithProtection()->setStatus('COMPLETED')->setPaymentProvider('STRIPE');

        $orderRepository = m::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $orderRepository->shouldReceive('findById')->andReturn($order);

        $refundHandler = m::mock(RefundOrderHandler::class);
        $refundHandler->shouldReceive('handle')
            ->once()
            ->withArgs(fn(RefundOrderDTO $dto) => abs($dto->amount - 20.00) < 0.001);

        $cancelService = m::mock(OrderCancelService::class);
        $cancelService->shouldReceive('cancelOrder')->once();

        $handler = new CancelOrderHandler(
            $cancelService,
            $orderRepository,
            $this->transactionalDatabase(),
            $refundHandler,
            $this->shareService(),
        );

        $handler->handle(new CancelOrderDTO(eventId: self::EVENT_ID, orderId: self::ORDER_ID, refund: true));
    }

    public function test_expired_paid_order_refunds_the_ticket_price_only(): void
    {
        $refundService = m::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('refundPayment')
            ->once()
            ->withArgs(function (...$args) {
                return ($args['amount'] ?? $args[0])->toMinorUnit() === 2000 && !array_key_exists('refundApplicationFee', $args);
            })
            ->andReturn(Refund::constructFrom(['id' => 're_2']));

        $eventRepository = m::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->andReturn(
            (new EventDomainObject())->setId(self::EVENT_ID)->setOrganizerId(1)
                ->setEventSettings(new EventSettingDomainObject())
                ->setOrganizer((new OrganizerDomainObject())->setId(1)->setName('Orga')->setEmail('orga@example.test'))
        );

        $clientFactory = m::mock(StripeClientFactory::class);
        $clientFactory->shouldReceive('createForPlatform')->andReturn(m::mock(StripeClient::class));

        $mailer = m::mock(Mailer::class);
        $mailer->shouldReceive('to->locale->send')->once();

        $locale = m::mock(CustomerLocaleResolver::class);
        $locale->shouldReceive('forEvent')->andReturn('fr');

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        (new StripeRefundExpiredOrderService(
            $refundService,
            $mailer,
            $logger,
            $eventRepository,
            $clientFactory,
            $locale,
            $this->shareService(),
        ))->refundExpiredOrder(
            PaymentIntent::constructFrom(['id' => 'pi_1', 'amount' => 2148, 'currency' => 'eur']),
            (new StripePaymentDomainObject())->setPaymentIntentId('pi_1')->setConnectedAccountId('acct_1'),
            $this->orderWithProtection()->setEmail('acheteur@example.test'),
        );
    }

    public function test_event_cancelled_by_the_organizer_refunds_everything_without_the_commission(): void
    {
        $refundService = m::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('refundPayment')
            ->once()
            ->withArgs(function (...$args) {
                $metadata = $args['metadata'] ?? $args[3] ?? [];

                return ($args['amount'] ?? $args[0])->toMinorUnit() === 2148
                    && !array_key_exists('refundApplicationFee', $args)
                    && ($metadata[StripePaymentIntentRefundService::REFUND_REASON_KEY] ?? null) === RefundReason::EVENT_CANCELLED_BY_ORGANIZER->name;
            })
            ->andReturn(Refund::constructFrom(['id' => 're_3']));

        $orderRepository = $this->orderRepositoryFor($this->orderWithProtection());
        $orderRepository->shouldReceive('updateFromArray')->once()->andReturn($this->orderWithProtection());

        $this->refundHandler($refundService, $orderRepository)->handle(new RefundOrderDTO(
            event_id: self::EVENT_ID,
            order_id: self::ORDER_ID,
            amount: 21.48,
            notify_buyer: false,
            cancel_order: false,
            reason: RefundReason::EVENT_CANCELLED_BY_ORGANIZER->name,
        ));
    }

    public function test_event_cancelled_refund_cannot_exceed_what_the_buyer_paid(): void
    {
        $refundService = m::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldNotReceive('refundPayment');

        $this->expectException(RefundNotPossibleException::class);

        $this->refundHandler($refundService, $this->orderRepositoryFor($this->orderWithProtection()))->handle(new RefundOrderDTO(
            event_id: self::EVENT_ID,
            order_id: self::ORDER_ID,
            amount: 21.49,
            notify_buyer: false,
            cancel_order: false,
            reason: RefundReason::EVENT_CANCELLED_BY_ORGANIZER->name,
        ));
    }

    public function test_event_cancelled_refund_takes_the_fees_off_the_organizer_sales(): void
    {
        $updates = [];
        $aggregate = (new \HiEvents\DomainObjects\EventStatisticDomainObject())
            ->setSalesTotalGross(21.48)
            ->setSalesTotalOrganizer(20.00);
        $statistics = m::mock(EventStatisticRepositoryInterface::class);
        $statistics->shouldReceive('findFirstWhere')->andReturn($aggregate);
        $statistics->shouldReceive('updateWhere')->andReturnUsing(function (array $attributes) use (&$updates) {
            $updates[] = $attributes;
            return 1;
        });

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        $daily = m::mock(EventDailyStatisticRepositoryInterface::class);
        $daily->shouldReceive('findFirstWhere')->andReturnNull();

        (new EventStatisticsRefundService($statistics, $daily, $logger, $this->shareService()))->updateForRefund(
            $this->orderWithProtection()->setCreatedAt('2026-11-04 10:00:00'),
            MoneyValue::fromFloat(21.48, 'EUR'),
            RefundReason::EVENT_CANCELLED_BY_ORGANIZER->name,
        );

        self::assertEqualsWithDelta(0.0, $updates[0]['sales_total_gross'], 0.001);
        self::assertEqualsWithDelta(-1.48, $updates[0]['sales_total_organizer'], 0.001);
    }

    public function test_statistics_count_the_organizer_sales_without_fees_nor_protection(): void
    {
        $order = $this->orderWithProtection()->setCreatedAt('2026-11-04 10:00:00');

        $orderRepository = m::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $orderRepository->shouldReceive('findById')->andReturn($order);

        $created = [];
        $statistics = m::mock(EventStatisticRepositoryInterface::class);
        $statistics->shouldReceive('findFirstWhere')->andReturnNull();
        $statistics->shouldReceive('create')->andReturnUsing(function (array $data) use (&$created) {
            $created['aggregate'] = $data;
            return new \HiEvents\DomainObjects\EventStatisticDomainObject();
        });

        $daily = m::mock(EventDailyStatisticRepositoryInterface::class);
        $daily->shouldReceive('findFirstWhere')->andReturnNull();
        $daily->shouldReceive('create')->andReturnUsing(function (array $data) use (&$created) {
            $created['daily'] = $data;
            return new \HiEvents\DomainObjects\EventDailyStatisticDomainObject();
        });

        $productRepository = $this->productRepository();
        $productRepository->shouldReceive('increment');

        $retrier = m::mock(Retrier::class);
        $retrier->shouldReceive('retry')->andReturnUsing(fn(callable $callableAction) => $callableAction(1));

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        (new EventStatisticsIncrementService(
            m::mock(PromoCodeRepositoryInterface::class),
            $productRepository,
            $statistics,
            $daily,
            $this->transactionalDatabase(),
            $orderRepository,
            $logger,
            $retrier,
            new OrderOrganizerShareService($productRepository, $orderRepository),
        ))->incrementForOrder($order);

        foreach (['aggregate', 'daily'] as $level) {
            self::assertEqualsWithDelta(21.48, $created[$level]['sales_total_gross'], 0.001);
            self::assertEqualsWithDelta(20.00, $created[$level]['sales_total_organizer'], 0.001);
        }
    }

    public function test_refunding_the_ticket_takes_it_off_the_organizer_sales(): void
    {
        $order = $this->orderWithProtection()->setCreatedAt('2026-11-04 10:00:00');

        $updates = [];
        $aggregate = (new \HiEvents\DomainObjects\EventStatisticDomainObject())
            ->setSalesTotalGross(21.48)
            ->setSalesTotalOrganizer(20.00);
        $statistics = m::mock(EventStatisticRepositoryInterface::class);
        $statistics->shouldReceive('findFirstWhere')->andReturn($aggregate);
        $statistics->shouldReceive('updateWhere')->andReturnUsing(function (array $attributes) use (&$updates) {
            $updates[] = $attributes;
            return 1;
        });

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        $daily = m::mock(EventDailyStatisticRepositoryInterface::class);
        $daily->shouldReceive('findFirstWhere')->andReturnNull();

        (new EventStatisticsRefundService($statistics, $daily, $logger, $this->shareService()))
            ->updateForRefund($order, MoneyValue::fromFloat(20.00, 'EUR'));

        self::assertEqualsWithDelta(1.48, $updates[0]['sales_total_gross'], 0.001);
        self::assertEqualsWithDelta(0.0, $updates[0]['sales_total_organizer'], 0.001);
    }

    private function feeService(?VatRateDeterminationService $vatRates = null): OrderApplicationFeeCalculationService
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(true);

        return new OrderApplicationFeeCalculationService(
            $config,
            m::mock(CurrencyConversionClientInterface::class),
            $vatRates ?? m::mock(VatRateDeterminationService::class),
            $this->productRepository(),
        );
    }

    private function shareService(): OrderOrganizerShareService
    {
        return new OrderOrganizerShareService($this->productRepository(), m::mock(OrderRepositoryInterface::class));
    }

    private function productRepository(): ProductRepositoryInterface|m\MockInterface
    {
        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findWhereIn')->andReturn(new Collection([
            (new ProductDomainObject())->setId(self::TICKET_ID)->setProductType('TICKET'),
            (new ProductDomainObject())->setId(self::PROTECTION_ID)->setProductType('GENERAL')->setIsRefundProtection(true),
        ]));

        return $productRepository;
    }

    private function accountConfiguration(): AccountConfigurationDomainObject
    {
        $configuration = m::mock(AccountConfigurationDomainObject::class);
        $configuration->shouldReceive('getFixedApplicationFee')->andReturn(0.99);
        $configuration->shouldReceive('getPercentageApplicationFee')->andReturn(0.0);
        $configuration->shouldReceive('getApplicationFeeCurrency')->andReturn('EUR');

        return $configuration;
    }

    private function ticketItem(): OrderItemDomainObject
    {
        return (new OrderItemDomainObject())
            ->setProductId(self::TICKET_ID)
            ->setProductType('TICKET')
            ->setPrice(20.00)
            ->setQuantity(1)
            ->setTotalBeforeAdditions(20.00)
            ->setTotalServiceFee(0.99)
            ->setTotalGross(20.99);
    }

    private function orderWithProtection(): OrderDomainObject
    {
        $protection = (new OrderItemDomainObject())
            ->setProductId(self::PROTECTION_ID)
            ->setProductType('GENERAL')
            ->setPrice(0.49)
            ->setQuantity(1)
            ->setTotalBeforeAdditions(0.49)
            ->setTotalServiceFee(0.0)
            ->setTotalGross(0.49);

        return (new OrderDomainObject())
            ->setId(self::ORDER_ID)
            ->setEventId(self::EVENT_ID)
            ->setCurrency('EUR')
            ->setTotalBeforeAdditions(20.49)
            ->setTotalFee(0.99)
            ->setTotalGross(21.48)
            ->setTotalRefunded(0.0)
            ->setOrderItems(new Collection([$this->ticketItem(), $protection]));
    }

    private function orderWithoutProtection(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(self::ORDER_ID)
            ->setEventId(self::EVENT_ID)
            ->setCurrency('EUR')
            ->setTotalBeforeAdditions(20.00)
            ->setTotalFee(0.99)
            ->setTotalGross(20.99)
            ->setTotalRefunded(0.0)
            ->setOrderItems(new Collection([$this->ticketItem()]));
    }

    private function refundDto(float $amount): RefundOrderDTO
    {
        return new RefundOrderDTO(
            event_id: self::EVENT_ID,
            order_id: self::ORDER_ID,
            amount: $amount,
            notify_buyer: false,
            cancel_order: false,
        );
    }

    private function orderRepositoryFor(OrderDomainObject $order): OrderRepositoryInterface|m\MockInterface
    {
        $order->setStripePayment((new StripePaymentDomainObject())->setPaymentIntentId('pi_1')->setConnectedAccountId('acct_1'));

        $orderRepository = m::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $orderRepository->shouldReceive('findFirstWhere')->andReturn($order);

        return $orderRepository;
    }

    private function refundHandler(StripePaymentIntentRefundService $refundService, OrderRepositoryInterface $orderRepository): RefundOrderHandler
    {
        $eventRepository = m::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->andReturn((new EventDomainObject())->setId(self::EVENT_ID));

        $clientFactory = m::mock(StripeClientFactory::class);
        $clientFactory->shouldReceive('createForPlatform')->andReturn(m::mock(StripeClient::class));

        return new RefundOrderHandler(
            $refundService,
            $orderRepository,
            $eventRepository,
            m::mock(Mailer::class),
            m::mock(OrderCancelService::class),
            $this->transactionalDatabase(),
            $clientFactory,
            m::mock(AttendeeRepositoryInterface::class),
            m::mock(PartialEditAttendeeHandler::class),
            m::mock(CustomerLocaleResolver::class),
            $this->shareService(),
        );
    }

    private function transactionalDatabase(): DatabaseManager
    {
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn(callable $callback) => $callback());

        return $databaseManager;
    }
}
