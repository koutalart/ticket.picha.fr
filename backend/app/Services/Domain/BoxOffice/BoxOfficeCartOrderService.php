<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice;

use Brick\Money\Money;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\InvalidProductPriceId;
use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeSaleItemDTO;
use HiEvents\Services\Domain\Attendee\AttendeePublicIdGenerator;
use HiEvents\Services\Domain\BoxOffice\DTO\BoxOfficeCartOrderDTO;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;

/**
 * One manually-created order for a whole box office cart: one order item per
 * price line, one attendee per ticket. Mirrors what CreateAttendeeHandler does
 * for a single attendee (stock, order status event, webhook) but fires the
 * order-level side effects once per cart. Must run inside the caller's
 * transaction, after stock and prices have been validated under lock.
 */
class BoxOfficeCartOrderService
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AttendeePublicIdGenerator $attendeePublicIdGenerator,
        private readonly OrderManagementService $orderManagementService,
        private readonly ProductQuantityUpdateService $productQuantityUpdateService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @param  CreateBoxOfficeSaleItemDTO[]  $items
     * @param  array<int, ProductPriceDomainObject>  $pricesById
     *
     * @throws NoTicketsAvailableException
     * @throws InvalidProductPriceId
     */
    public function createOrder(
        int $eventId,
        array $items,
        array $pricesById,
        ?string $firstName,
        ?string $lastName,
        string $email,
        string $locale,
        bool $sendConfirmationEmail,
    ): BoxOfficeCartOrderDTO {
        $event = $this->eventRepository->findById($eventId);

        $total = Money::of(0, $event->getCurrency());
        foreach ($items as $item) {
            $total = $total->plus(Money::of($pricesById[$item->product_price_id]->getPrice(), $event->getCurrency())
                ->multipliedBy($item->quantity));
        }

        $order = $this->orderRepository->create([
            OrderDomainObjectAbstract::TOTAL_GROSS => $total->getAmount()->toFloat(),
            OrderDomainObjectAbstract::FIRST_NAME => $firstName,
            OrderDomainObjectAbstract::LAST_NAME => $lastName,
            OrderDomainObjectAbstract::EMAIL => $email,
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
            OrderDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            OrderDomainObjectAbstract::PAYMENT_STATUS => $total->isZero()
                ? OrderPaymentStatus::NO_PAYMENT_REQUIRED->name
                : OrderPaymentStatus::PAYMENT_RECEIVED->name,
            OrderDomainObjectAbstract::CURRENCY => $event->getCurrency(),
            OrderDomainObjectAbstract::PUBLIC_ID => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::IS_MANUALLY_CREATED => true,
            OrderDomainObjectAbstract::LOCALE => $locale,
        ]);

        $orderItems = collect();
        $attendees = [];

        foreach ($items as $item) {
            $product = $this->findTicketProduct($eventId, $item);
            $unitPrice = $pricesById[$item->product_price_id]->getPrice();
            $lineTotal = Money::of($unitPrice, $event->getCurrency())->multipliedBy($item->quantity)->getAmount()->toFloat();

            $orderItems->push($this->orderRepository->addOrderItem([
                OrderItemDomainObjectAbstract::PRODUCT_ID => $item->product_id,
                OrderItemDomainObjectAbstract::QUANTITY => $item->quantity,
                OrderItemDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS => $lineTotal,
                OrderItemDomainObjectAbstract::TOTAL_GROSS => $lineTotal,
                OrderItemDomainObjectAbstract::TOTAL_TAX => 0,
                OrderItemDomainObjectAbstract::TOTAL_SERVICE_FEE => 0,
                OrderItemDomainObjectAbstract::PRICE => $unitPrice,
                OrderItemDomainObjectAbstract::ORDER_ID => $order->getId(),
                OrderItemDomainObjectAbstract::ITEM_NAME => $product->getTitle(),
                OrderItemDomainObjectAbstract::PRODUCT_PRICE_ID => $item->product_price_id,
                OrderItemDomainObjectAbstract::TAXES_AND_FEES_ROLLUP => [],
            ]));

            for ($i = 0; $i < $item->quantity; $i++) {
                $attendees[] = $this->attendeeRepository->create([
                    AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
                    AttendeeDomainObjectAbstract::PRODUCT_ID => $item->product_id,
                    AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $item->product_price_id,
                    AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
                    AttendeeDomainObjectAbstract::EMAIL => $email,
                    AttendeeDomainObjectAbstract::FIRST_NAME => $firstName,
                    AttendeeDomainObjectAbstract::LAST_NAME => $lastName,
                    AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
                    AttendeeDomainObjectAbstract::PUBLIC_ID => $this->attendeePublicIdGenerator->generateUnique(),
                    AttendeeDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
                    AttendeeDomainObjectAbstract::LOCALE => $locale,
                ]);
            }

            $this->productQuantityUpdateService->increaseQuantitySold($item->product_price_id, $item->quantity);
        }

        $order = $this->orderManagementService->updateOrderTotals($order, $orderItems);

        event(new OrderStatusChangedEvent(
            order: $order,
            sendEmails: $sendConfirmationEmail,
        ));

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(DomainEventType::ORDER_CREATED, $order->getId())
        );

        return new BoxOfficeCartOrderDTO($order, $attendees);
    }

    /**
     * @throws NoTicketsAvailableException
     * @throws InvalidProductPriceId
     */
    private function findTicketProduct(int $eventId, CreateBoxOfficeSaleItemDTO $item): ProductDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findFirstWhere([
                ProductDomainObjectAbstract::ID => $item->product_id,
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
            ]);

        if ($product === null) {
            throw new NoTicketsAvailableException(__('This ticket is invalid'));
        }

        $priceIds = $product->getProductPrices()->map(fn (ProductPriceDomainObject $price) => $price->getId());
        if (! $priceIds->contains($item->product_price_id)) {
            throw new InvalidProductPriceId(__('The product price ID is invalid.'));
        }

        return $product;
    }
}
