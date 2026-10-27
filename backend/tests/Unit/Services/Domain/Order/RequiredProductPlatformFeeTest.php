<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderPlatformFeePassThroughService;
use HiEvents\Services\Domain\Order\RefundProtection\RefundProtectionPolicy;
use HiEvents\Services\Domain\Order\Vat\VatRateDeterminationService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\Product\DTO\PriceDTO;
use HiEvents\Services\Domain\Product\ProductFilterService;
use HiEvents\Services\Domain\Product\ProductPriceService;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use HiEvents\Services\Domain\Tax\DTO\TaxCalculationResponse;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Infrastructure\CurrencyConversion\CurrencyConversionClientInterface;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class RequiredProductPlatformFeeTest extends TestCase
{
    private const EVENT_ID = 15;
    private const TICKET_ID = 32;
    private const OPTION_ID = 33;
    private const PLATFORM_FEE_PER_UNIT = 0.99;

    public function test_application_fee_counts_the_tickets_only_and_ignores_the_required_option(): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(true);

        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findWhereIn')->andReturn(new Collection([
            $this->makeProduct(self::TICKET_ID, 'TICKET', 15.00, false),
            $this->makeProduct(self::OPTION_ID, 'GENERAL', 0.49, true),
        ]));

        $service = new OrderApplicationFeeCalculationService(
            $config,
            m::mock(CurrencyConversionClientInterface::class),
            m::mock(VatRateDeterminationService::class),
            $productRepository,
        );

        $tickets = (new OrderItemDomainObject())
            ->setProductId(self::TICKET_ID)
            ->setPrice(15.00)
            ->setQuantity(2)
            ->setTotalGross(31.98);
        $option = (new OrderItemDomainObject())
            ->setProductId(self::OPTION_ID)
            ->setPrice(0.49)
            ->setQuantity(1)
            ->setTotalGross(0.49);

        $order = (new OrderDomainObject())
            ->setCurrency('EUR')
            ->setTotalGross(32.47)
            ->setOrderItems(new Collection([$tickets, $option]));

        $fee = $service->calculateApplicationFee($this->makeAccountConfiguration(0.99, 0), $order);
        self::assertEqualsWithDelta(1.98, $fee->grossApplicationFee->toFloat(), 0.001);

        $percentageFee = $service->calculateApplicationFee($this->makeAccountConfiguration(0, 10), $order);
        self::assertEqualsWithDelta(3.198, $percentageFee->grossApplicationFee->toFloat(), 0.01);
    }

    public function test_order_item_of_a_required_product_has_no_platform_fee(): void
    {
        $platformFeeService = m::mock(OrderPlatformFeePassThroughService::class);
        $platformFeeService->shouldReceive('calculatePlatformFee')
            ->andReturnUsing(fn($account, $settings, float $total, int $quantity) => self::PLATFORM_FEE_PER_UNIT * $quantity);

        $products = [
            self::TICKET_ID => $this->makeProduct(self::TICKET_ID, 'TICKET', 15.00, false),
            self::OPTION_ID => $this->makeProduct(self::OPTION_ID, 'GENERAL', 0.49, true),
        ];

        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $productRepository->shouldReceive('findFirstWhere')
            ->andReturnUsing(fn(array $where) => $products[$where['id']]);

        $taxService = m::mock(TaxAndFeeCalculationService::class);
        $taxService->shouldReceive('calculateTaxAndFeesForProduct')
            ->andReturn(new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []));

        $priceService = m::mock(ProductPriceService::class);
        $priceService->shouldReceive('getPrice')
            ->andReturnUsing(fn(ProductDomainObject $product) => new PriceDTO($product->getProductPrices()->first()->getPrice()));

        $addedItems = [];
        $orderRepository = m::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('addOrderItem')
            ->andReturnUsing(function (array $data) use (&$addedItems) {
                $addedItems[$data['product_id']] = $data;
                return new OrderItemDomainObject();
            });

        $service = new OrderItemProcessingService(
            $orderRepository,
            $productRepository,
            $taxService,
            $priceService,
            $platformFeeService,
            $this->makeAccountRepository(),
            $this->makeEventRepository(),
        );

        $service->process(
            order: (new OrderDomainObject())->setId(1),
            productsOrderDetails: new Collection([
                new ProductOrderDetailsDTO(self::TICKET_ID, new Collection([new OrderProductPriceDTO(2, self::TICKET_ID * 10)])),
                new ProductOrderDetailsDTO(self::OPTION_ID, new Collection([new OrderProductPriceDTO(1, self::OPTION_ID * 10)])),
            ]),
            event: (new EventDomainObject())->setId(self::EVENT_ID)->setCurrency('EUR'),
            promoCode: null,
        );

        self::assertEqualsWithDelta(1.98, $addedItems[self::TICKET_ID]['total_service_fee'], 0.001);
        self::assertEqualsWithDelta(31.98, $addedItems[self::TICKET_ID]['total_gross'], 0.001);
        self::assertEqualsWithDelta(0.0, $addedItems[self::OPTION_ID]['total_service_fee'], 0.001);
        self::assertEqualsWithDelta(0.49, $addedItems[self::OPTION_ID]['total_gross'], 0.001);
        self::assertEqualsWithDelta(32.47, $addedItems[self::TICKET_ID]['total_gross'] + $addedItems[self::OPTION_ID]['total_gross'], 0.001);
    }

    public function test_required_product_counted_per_ticket_has_no_platform_fee_on_quantity_three(): void
    {
        $platformFeeService = m::mock(OrderPlatformFeePassThroughService::class);
        $platformFeeService->shouldReceive('calculatePlatformFee')
            ->andReturnUsing(fn($account, $settings, float $total, int $quantity) => self::PLATFORM_FEE_PER_UNIT * $quantity);

        $option = $this->makeProduct(self::OPTION_ID, 'GENERAL', 0.49, true)->setRequiredQuantityMode('PER_TICKET');
        $products = [
            self::TICKET_ID => $this->makeProduct(self::TICKET_ID, 'TICKET', 15.00, false),
            self::OPTION_ID => $option,
        ];

        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $productRepository->shouldReceive('findFirstWhere')
            ->andReturnUsing(fn(array $where) => $products[$where['id']]);
        $productRepository->shouldReceive('findWhereIn')->andReturn(new Collection(array_values($products)));

        $taxService = m::mock(TaxAndFeeCalculationService::class);
        $taxService->shouldReceive('calculateTaxAndFeesForProduct')
            ->andReturn(new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []));

        $priceService = m::mock(ProductPriceService::class);
        $priceService->shouldReceive('getPrice')
            ->andReturnUsing(fn(ProductDomainObject $product) => new PriceDTO($product->getProductPrices()->first()->getPrice()));

        $addedItems = [];
        $orderRepository = m::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('addOrderItem')
            ->andReturnUsing(function (array $data) use (&$addedItems) {
                $addedItems[$data['product_id']] = $data;
                return new OrderItemDomainObject();
            });

        (new OrderItemProcessingService(
            $orderRepository,
            $productRepository,
            $taxService,
            $priceService,
            $platformFeeService,
            $this->makeAccountRepository(),
            $this->makeEventRepository(),
        ))->process(
            order: (new OrderDomainObject())->setId(1),
            productsOrderDetails: new Collection([
                new ProductOrderDetailsDTO(self::TICKET_ID, new Collection([new OrderProductPriceDTO(3, self::TICKET_ID * 10)])),
                new ProductOrderDetailsDTO(self::OPTION_ID, new Collection([new OrderProductPriceDTO(3, self::OPTION_ID * 10)])),
            ]),
            event: (new EventDomainObject())->setId(self::EVENT_ID)->setCurrency('EUR'),
            promoCode: null,
        );

        self::assertEqualsWithDelta(2.97, $addedItems[self::TICKET_ID]['total_service_fee'], 0.001);
        self::assertEqualsWithDelta(0.0, $addedItems[self::OPTION_ID]['total_service_fee'], 0.001);
        self::assertEqualsWithDelta(1.47, $addedItems[self::OPTION_ID]['total_gross'], 0.001);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(true);
        $applicationFeeService = new OrderApplicationFeeCalculationService(
            $config,
            m::mock(CurrencyConversionClientInterface::class),
            m::mock(VatRateDeterminationService::class),
            $productRepository,
        );

        $order = (new OrderDomainObject())
            ->setCurrency('EUR')
            ->setTotalGross(49.44)
            ->setOrderItems(new Collection([
                (new OrderItemDomainObject())->setProductId(self::TICKET_ID)->setPrice(15.00)->setQuantity(3)->setTotalGross(47.97),
                (new OrderItemDomainObject())->setProductId(self::OPTION_ID)->setPrice(0.49)->setQuantity(3)->setTotalGross(1.47),
            ]));

        $fee = $applicationFeeService->calculateApplicationFee($this->makeAccountConfiguration(0.99, 0), $order);
        self::assertEqualsWithDelta(2.97, $fee->grossApplicationFee->toFloat(), 0.001);
    }

    public function test_public_price_of_a_required_product_has_no_platform_fee(): void
    {
        $platformFeeService = m::mock(OrderPlatformFeePassThroughService::class);
        $platformFeeService->shouldReceive('calculatePlatformFee')->andReturn(self::PLATFORM_FEE_PER_UNIT);

        $taxService = m::mock(TaxAndFeeCalculationService::class);
        $taxService->shouldReceive('calculateTaxAndFeesForProductPrice')
            ->andReturn(new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []));

        $quantitiesService = m::mock(AvailableProductQuantitiesFetchService::class);
        $quantitiesService->shouldReceive('getAvailableProductQuantities')
            ->andReturn(new AvailableProductQuantitiesResponseDTO(productQuantities: new Collection()));

        $service = new ProductFilterService(
            $taxService,
            m::mock(ProductPriceService::class),
            $quantitiesService,
            $platformFeeService,
            $this->makeAccountRepository(),
            $this->makeEventRepository(),
            new ProductUnlockService(m::mock(ProductRepositoryInterface::class), $quantitiesService),
            new RefundProtectionPolicy(app('config')),
        );

        $result = $service->filterProductList(new Collection([
            $this->makeProduct(self::TICKET_ID, 'TICKET', 15.00, false),
            $this->makeProduct(self::OPTION_ID, 'GENERAL', 0.49, true),
        ]), hideSoldOutProducts: false);

        $feeOf = fn(int $id) => $result
            ->first(fn(ProductDomainObject $product) => $product->getId() === $id)
            ->getProductPrices()->first()->getFeeTotal();

        self::assertEqualsWithDelta(self::PLATFORM_FEE_PER_UNIT, $feeOf(self::TICKET_ID), 0.001);
        self::assertEqualsWithDelta(0.0, $feeOf(self::OPTION_ID), 0.001);
    }

    public function test_optional_refund_protection_never_carries_a_platform_fee(): void
    {
        $option = $this->makeProduct(self::OPTION_ID, 'GENERAL', 0.49, false)->setIsRefundProtection(true);
        $ticket = $this->makeProduct(self::TICKET_ID, 'TICKET', 15.00, false);

        $platformFeeService = m::mock(OrderPlatformFeePassThroughService::class);
        $platformFeeService->shouldReceive('calculatePlatformFee')
            ->andReturnUsing(fn($account, $settings, float $total, int $quantity) => self::PLATFORM_FEE_PER_UNIT * $quantity);

        $taxService = m::mock(TaxAndFeeCalculationService::class);
        $taxService->shouldReceive('calculateTaxAndFeesForProduct')
            ->andReturn(new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []));
        $taxService->shouldReceive('calculateTaxAndFeesForProductPrice')
            ->andReturn(new TaxCalculationResponse(feeTotal: 0.0, taxTotal: 0.0, rollUp: []));

        $priceService = m::mock(ProductPriceService::class);
        $priceService->shouldReceive('getPrice')
            ->andReturnUsing(fn(ProductDomainObject $product) => new PriceDTO($product->getProductPrices()->first()->getPrice()));

        $line = (new OrderItemProcessingService(
            m::mock(OrderRepositoryInterface::class),
            m::mock(ProductRepositoryInterface::class),
            $taxService,
            $priceService,
            $platformFeeService,
            $this->makeAccountRepository(),
            $this->makeEventRepository(),
        ))->calculateItemForProduct(
            order: (new OrderDomainObject())->setId(1),
            product: $option,
            productPrice: new OrderProductPriceDTO(1, self::OPTION_ID * 10),
            event: (new EventDomainObject())->setId(self::EVENT_ID)->setCurrency('EUR'),
            promoCode: null,
        );

        self::assertEqualsWithDelta(0.0, $line['total_service_fee'], 0.001);
        self::assertEqualsWithDelta(0.49, $line['total_gross'], 0.001);

        $quantitiesService = m::mock(AvailableProductQuantitiesFetchService::class);
        $quantitiesService->shouldReceive('getAvailableProductQuantities')
            ->andReturn(new AvailableProductQuantitiesResponseDTO(productQuantities: new Collection()));

        $result = (new ProductFilterService(
            $taxService,
            m::mock(ProductPriceService::class),
            $quantitiesService,
            $platformFeeService,
            $this->makeAccountRepository(),
            $this->makeEventRepository(),
            new ProductUnlockService(m::mock(ProductRepositoryInterface::class), $quantitiesService),
            new RefundProtectionPolicy(app('config')),
        ))->filterProductList(new Collection([$ticket, $option]), hideSoldOutProducts: false);

        $feeOf = fn(int $id) => $result
            ->first(fn(ProductDomainObject $product) => $product->getId() === $id)
            ->getProductPrices()->first()->getFeeTotal();

        self::assertEqualsWithDelta(self::PLATFORM_FEE_PER_UNIT, $feeOf(self::TICKET_ID), 0.001);
        self::assertEqualsWithDelta(0.0, $feeOf(self::OPTION_ID), 0.001);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(true);
        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$ticket, $option]));

        $fee = (new OrderApplicationFeeCalculationService(
            $config,
            m::mock(CurrencyConversionClientInterface::class),
            m::mock(VatRateDeterminationService::class),
            $productRepository,
        ))->calculateApplicationFee($this->makeAccountConfiguration(0.99, 0), (new OrderDomainObject())
            ->setCurrency('EUR')
            ->setTotalGross(32.47)
            ->setOrderItems(new Collection([
                (new OrderItemDomainObject())->setProductId(self::TICKET_ID)->setPrice(15.00)->setQuantity(2)->setTotalGross(31.98),
                (new OrderItemDomainObject())->setProductId(self::OPTION_ID)->setPrice(0.49)->setQuantity(1)->setTotalGross(0.49),
            ])));

        self::assertEqualsWithDelta(1.98, $fee->grossApplicationFee->toFloat(), 0.001);
    }

    private function makeProduct(int $id, string $productType, float $price, bool $required): ProductDomainObject
    {
        $productPrice = (new ProductPriceDomainObject())
            ->setId($id * 10)
            ->setProductId($id)
            ->setPrice($price);

        return (new ProductDomainObject())
            ->setId($id)
            ->setEventId(self::EVENT_ID)
            ->setTitle('Product ' . $id)
            ->setOrder($id)
            ->setType('PAID')
            ->setProductType($productType)
            ->setIsRequired($required)
            ->setProductPrices(new Collection([$productPrice]));
    }

    private function makeAccountConfiguration(float $fixedFee, float $percentageFee): AccountConfigurationDomainObject
    {
        $configuration = m::mock(AccountConfigurationDomainObject::class);
        $configuration->shouldReceive('getFixedApplicationFee')->andReturn($fixedFee);
        $configuration->shouldReceive('getPercentageApplicationFee')->andReturn($percentageFee);
        $configuration->shouldReceive('getApplicationFeeCurrency')->andReturn('EUR');

        return $configuration;
    }

    private function makeAccountRepository(): AccountRepositoryInterface
    {
        $account = m::mock(AccountDomainObject::class);
        $account->shouldReceive('getConfiguration')->andReturn($this->makeAccountConfiguration(0.99, 0));

        $repository = m::mock(AccountRepositoryInterface::class);
        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository->shouldReceive('findByEventId')->with(self::EVENT_ID)->andReturn($account);

        return $repository;
    }

    private function makeEventRepository(): EventRepositoryInterface
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getEventSettings')->andReturn(new EventSettingDomainObject());
        $event->shouldReceive('getCurrency')->andReturn('EUR');

        $repository = m::mock(EventRepositoryInterface::class);
        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository->shouldReceive('findById')->with(self::EVENT_ID)->andReturn($event);

        return $repository;
    }
}
