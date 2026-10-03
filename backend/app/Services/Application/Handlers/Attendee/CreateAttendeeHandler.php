<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use Brick\Money\Money;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\InvalidProductPriceId;
use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Helper\Currency;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Domain\Attendee\AttendeePublicIdGenerator;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\DatabaseManager;
use Throwable;

class CreateAttendeeHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface     $attendeeRepository,
        private readonly OrderRepositoryInterface        $orderRepository,
        private readonly ProductRepositoryInterface      $productRepository,
        private readonly ProductPriceRepositoryInterface $productPriceRepository,
        private readonly EventRepositoryInterface        $eventRepository,
        private readonly ProductQuantityUpdateService    $productQuantityAdjustmentService,
        private readonly DatabaseManager                 $databaseManager,
        private readonly TaxAndFeeCalculationService     $taxAndFeeCalculationService,
        private readonly OrderManagementService          $orderManagementService,
        private readonly DomainEventDispatcherService    $domainEventDispatcherService,
    )
    {
    }

    /**
     * @throws NoTicketsAvailableException
     * @throws InvalidProductPriceId
     * @throws Throwable
     */
    public function handle(CreateAttendeeDTO $attendeeDTO): AttendeeDomainObject
    {
        return $this->databaseManager->transaction(function () use ($attendeeDTO) {
            /** @var ProductDomainObject|null $product */
            $product = $this->productRepository
                ->loadRelation(ProductPriceDomainObject::class)
                ->loadRelation(TaxAndFeesDomainObject::class)
                ->findFirstWhere([
                    ProductDomainObjectAbstract::ID => $attendeeDTO->product_id,
                    ProductDomainObjectAbstract::EVENT_ID => $attendeeDTO->event_id,
                    ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
                ]);

            if (!$product) {
                throw new NoTicketsAvailableException(__('This ticket is invalid'));
            }

            $productPriceId = $this->getProductPriceId($attendeeDTO, $product);

            $productPrice = $this->productPriceRepository->lockForUpdateById($productPriceId);
            if (!$productPrice) {
                throw new InvalidProductPriceId(__('The product price ID is invalid.'));
            }

            $availableQuantity = $this->productRepository->getQuantityRemainingForProductPrice(
                $attendeeDTO->product_id,
                $productPriceId,
            );

            if ($availableQuantity <= 0) {
                throw new NoTicketsAvailableException(__('There are no tickets available. ' .
                    'If you would like to assign a product to this attendee,' .
                    ' please adjust the product\'s available quantity.'));
            }

            $price = $attendeeDTO->is_free ? 0.0 : $productPrice->getPrice();
            $taxesAndFees = $this->taxAndFeeCalculationService->calculateTaxAndFeesForProduct($product, $price);
            $totalGross = Currency::round($price + $taxesAndFees->taxTotal + $taxesAndFees->feeTotal);

            $order = $this->createOrder($attendeeDTO, $totalGross);

            $orderItem = $this->orderRepository->addOrderItem([
                OrderItemDomainObjectAbstract::PRODUCT_ID => $attendeeDTO->product_id,
                OrderItemDomainObjectAbstract::QUANTITY => 1,
                OrderItemDomainObjectAbstract::PRICE => $price,
                OrderItemDomainObjectAbstract::PRICE_BEFORE_DISCOUNT => $price,
                OrderItemDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS => $price,
                OrderItemDomainObjectAbstract::TOTAL_TAX => $taxesAndFees->taxTotal,
                OrderItemDomainObjectAbstract::TOTAL_SERVICE_FEE => $taxesAndFees->feeTotal,
                OrderItemDomainObjectAbstract::TOTAL_GROSS => $totalGross,
                OrderItemDomainObjectAbstract::ORDER_ID => $order->getId(),
                OrderItemDomainObjectAbstract::ITEM_NAME => $product->getTitle(),
                OrderItemDomainObjectAbstract::PRODUCT_PRICE_ID => $productPriceId,
                OrderItemDomainObjectAbstract::TAXES_AND_FEES_ROLLUP => $taxesAndFees->rollUp,
            ]);

            $attendee = $this->createAttendee($order, $attendeeDTO, $productPriceId);

            $this->orderManagementService->updateOrderTotals($order, collect([$orderItem]));

            $this->productQuantityAdjustmentService->increaseQuantitySold(priceId: $productPriceId);

            event(new OrderStatusChangedEvent(
                order: $order,
                sendEmails: $attendeeDTO->send_confirmation_email,
            ));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(DomainEventType::ORDER_CREATED, $order->getId())
            );

            return $attendee;
        });
    }

    private function createOrder(CreateAttendeeDTO $attendeeDTO, float $totalGross): OrderDomainObject
    {
        $event = $this->eventRepository->findById($attendeeDTO->event_id);
        $total = Money::of($totalGross, $event->getCurrency());

        return $this->orderRepository->create([
            OrderDomainObjectAbstract::TOTAL_GROSS => $total->getAmount()->toFloat(),
            OrderDomainObjectAbstract::FIRST_NAME => $attendeeDTO->first_name,
            OrderDomainObjectAbstract::LAST_NAME => $attendeeDTO->last_name,
            OrderDomainObjectAbstract::EMAIL => $attendeeDTO->email,
            OrderDomainObjectAbstract::EVENT_ID => $attendeeDTO->event_id,
            OrderDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            OrderDomainObjectAbstract::PAYMENT_STATUS => $total->isZero()
                ? OrderPaymentStatus::NO_PAYMENT_REQUIRED->name
                : OrderPaymentStatus::PAYMENT_RECEIVED->name,
            OrderDomainObjectAbstract::CURRENCY => $event->getCurrency(),
            OrderDomainObjectAbstract::PUBLIC_ID => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::IS_MANUALLY_CREATED => true,
            OrderDomainObjectAbstract::LOCALE => $attendeeDTO->locale,
        ]);
    }

    /**
     * @throws InvalidProductPriceId
     */
    private function getProductPriceId(CreateAttendeeDTO $attendeeDTO, ProductDomainObject $product): int
    {
        $priceIds = $product->getProductPrices()->map(fn(ProductPriceDomainObject $productPrice) => $productPrice->getId());

        if ($attendeeDTO->product_price_id) {
            if (!$priceIds->contains($attendeeDTO->product_price_id)) {
                throw new InvalidProductPriceId(__('The product price ID is invalid.'));
            }
            return $attendeeDTO->product_price_id;
        }

        /** @var ProductPriceDomainObject $productPrice */
        $productPrice = $product->getProductPrices()->first();

        if ($productPrice) {
            return $productPrice->getId();
        }

        throw new InvalidProductPriceId(__('The product price ID is invalid.'));
    }

    private function createAttendee(OrderDomainObject $order, CreateAttendeeDTO $attendeeDTO, int $productPriceId): AttendeeDomainObject
    {
        return $this->attendeeRepository->create([
            AttendeeDomainObjectAbstract::EVENT_ID => $order->getEventId(),
            AttendeeDomainObjectAbstract::PRODUCT_ID => $attendeeDTO->product_id,
            AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $productPriceId,
            AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
            AttendeeDomainObjectAbstract::EMAIL => $attendeeDTO->email,
            AttendeeDomainObjectAbstract::FIRST_NAME => $attendeeDTO->first_name,
            AttendeeDomainObjectAbstract::LAST_NAME => $attendeeDTO->last_name,
            AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
            AttendeeDomainObjectAbstract::PUBLIC_ID => app(AttendeePublicIdGenerator::class)->generateUnique(),
            AttendeeDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            AttendeeDomainObjectAbstract::LOCALE => $attendeeDTO->locale,
        ]);
    }
}
