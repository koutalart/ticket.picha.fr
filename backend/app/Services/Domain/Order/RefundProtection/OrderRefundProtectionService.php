<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\RefundProtection;

use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Exceptions\RefundProtectionUnavailableException;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Order\RefundProtection\DTO\RefundProtectionOfferDTO;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The refund protection (is_refund_protection, not a ticket) is no longer picked in the product list:
 * it is offered as a checkbox right before payment. Its line is always computed here, with the same
 * calculation as at order creation (price, promo code, taxes, fees, platform fee).
 */
class OrderRefundProtectionService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly OrderItemProcessingService $orderItemProcessingService,
        private readonly OrderManagementService $orderManagementService,
        private readonly AvailableProductQuantitiesFetchService $availableQuantitiesFetchService,
        private readonly ProductUnlockService $productUnlockService,
    ) {}

    public static function isCheckoutRefundProtection(ProductDomainObject $product): bool
    {
        return $product->getIsRefundProtection() === true
            && $product->getProductType() !== ProductType::TICKET->name;
    }

    public function getOffer(OrderDomainObject $order, EventDomainObject $event): ?RefundProtectionOfferDTO
    {
        if ($event->getEventSettings()?->getSingleProductPerOrder()) {
            return null;
        }

        $orderItems = $this->getOrderItems($order->getId());
        $protectionProducts = $this->getProtectionProducts($event->getId());
        $selectedItems = $this->getProtectionItems($orderItems, $protectionProducts);

        if ($selectedItems->isNotEmpty()) {
            $product = $protectionProducts->first(
                fn (ProductDomainObject $product) => $product->getId() === $selectedItems->first()->getProductId()
            );

            return $this->buildOfferFromItems($product, $selectedItems, $order);
        }

        $availability = $this->getAvailability($event->getId());
        $product = $this->findOfferProduct($protectionProducts, $availability);

        if ($product === null) {
            return null;
        }

        if (! $product->getIsRequired() && ! $this->hasPaidItems($orderItems, $protectionProducts)) {
            return null;
        }

        $line = $this->computeLine($order, $event, $product, $orderItems, $protectionProducts, $availability);

        if ($line === null) {
            return null;
        }

        return new RefundProtectionOfferDTO(
            product_id: $product->getId(),
            title: $product->getTitle(),
            description: $this->getPlainDescription($product),
            quantity: $line['quantity'],
            is_per_ticket: $this->isPerTicket($product),
            total_before_additions: (float) $line['total_before_additions'],
            total_tax: (float) $line['total_tax'],
            total_fee: (float) $line['total_service_fee'],
            total_gross: (float) $line['total_gross'],
            currency: $order->getCurrency(),
            is_selected: false,
            is_locked: false,
        );
    }

    /**
     * Adds (enabled) or removes the protection line and recalculates the order totals.
     *
     * @throws RefundProtectionUnavailableException
     */
    public function apply(OrderDomainObject $order, EventDomainObject $event, bool $enabled): OrderDomainObject
    {
        if ($event->getEventSettings()?->getSingleProductPerOrder()) {
            throw new RefundProtectionUnavailableException(__('The cancellation protection is not available for this order.'));
        }

        $protectionProducts = $this->getProtectionProducts($event->getId());
        $orderItems = $this->getOrderItems($order->getId());
        $selectedItems = $this->getProtectionItems($orderItems, $protectionProducts);

        if (! $enabled) {
            $isLocked = $selectedItems->contains(fn (OrderItemDomainObject $item) => $protectionProducts
                ->first(fn (ProductDomainObject $product) => $product->getId() === $item->getProductId())
                ?->getIsRequired() === true);

            if ($isLocked) {
                throw new RefundProtectionUnavailableException(__('The cancellation protection is included in every order for this event.'));
            }

            if ($selectedItems->isEmpty()) {
                return $order;
            }

            $this->removeProtectionItems($order, $protectionProducts);

            return $this->refreshTotals($order);
        }

        $this->removeProtectionItems($order, $protectionProducts);
        $orderItems = $this->getOrderItems($order->getId());

        $availability = $this->getAvailability($event->getId());
        $product = $this->findOfferProduct($protectionProducts, $availability);

        if ($product === null
            || (! $product->getIsRequired() && ! $this->hasPaidItems($orderItems, $protectionProducts))) {
            throw new RefundProtectionUnavailableException(__('The cancellation protection is not available for this order.'));
        }

        $line = $this->computeLine($order, $event, $product, $orderItems, $protectionProducts, $availability);

        if ($line === null) {
            throw new RefundProtectionUnavailableException(__('The cancellation protection is not available for this order.'));
        }

        $this->orderRepository->addOrderItem($line);

        return $this->refreshTotals($order);
    }

    /**
     * Run once the order lines are saved at creation: a required protection is added automatically,
     * and a protection sent by an older version of the page is put back to the right quantity.
     */
    public function syncAfterOrderCreation(OrderDomainObject $order, EventDomainObject $event): OrderDomainObject
    {
        if ($event->getEventSettings()?->getSingleProductPerOrder()) {
            return $order;
        }

        $protectionProducts = $this->getProtectionProducts($event->getId());

        if ($protectionProducts->isEmpty()) {
            return $order;
        }

        $orderItems = $this->getOrderItems($order->getId());
        $hasSelectedItems = $this->getProtectionItems($orderItems, $protectionProducts)->isNotEmpty();
        $isRequired = $protectionProducts->contains(fn (ProductDomainObject $product) => $product->getIsRequired());

        if (! $hasSelectedItems && ! $isRequired) {
            return $order;
        }

        try {
            return $this->apply($order, $event, true);
        } catch (RefundProtectionUnavailableException) {
            $this->removeProtectionItems($order, $protectionProducts);

            return $this->refreshTotals($order);
        }
    }

    /**
     * @return Collection<int, ProductDomainObject>
     */
    private function getProtectionProducts(int $eventId): Collection
    {
        return $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->findWhere([
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::IS_REFUND_PROTECTION => true,
            ])
            ->filter(fn (ProductDomainObject $product) => self::isCheckoutRefundProtection($product))
            ->sortBy(fn (ProductDomainObject $product) => [(int) $product->getOrder(), $product->getId()])
            ->values();
    }

    /**
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function findOfferProduct(Collection $protectionProducts, AvailableProductQuantitiesResponseDTO $availability): ?ProductDomainObject
    {
        $lockedIds = $this->productUnlockService->getLockedProductIds($protectionProducts, $availability);

        return $protectionProducts->first(fn (ProductDomainObject $product) => ! $product->getIsHidden()
            && ! $product->getIsSalesPaused()
            && ! $product->getIsHiddenWithoutPromoCode()
            && ! $product->isBeforeSaleStartDate()
            && ! $product->isAfterSaleEndDate()
            && in_array($product->getType(), [ProductPriceType::PAID->name, ProductPriceType::TIERED->name], true)
            && ! in_array($product->getId(), $lockedIds, true)
            && $this->findOfferPrice($product, $availability) !== null
        );
    }

    private function findOfferPrice(ProductDomainObject $product, AvailableProductQuantitiesResponseDTO $availability): ?ProductPriceDomainObject
    {
        return $product->getProductPrices()
            ?->sortBy(fn (ProductPriceDomainObject $price) => [(int) $price->getOrder(), $price->getId()])
            ->first(fn (ProductPriceDomainObject $price) => ! $price->getIsHidden()
                && ! $price->isBeforeSaleStartDate()
                && ! $price->isAfterSaleEndDate()
                && (float) $price->getPrice() > 0
                && $this->getQuantityAvailable($product->getId(), $price->getId(), $availability) > 0
            );
    }

    private function getQuantityAvailable(int $productId, int $priceId, AvailableProductQuantitiesResponseDTO $availability): int
    {
        $row = $availability->productQuantities
            ->first(fn ($row) => $row->product_id === $productId && $row->price_id === $priceId);

        if ($row === null) {
            return 0;
        }

        return $row->quantity_available === Constants::INFINITE ? PHP_INT_MAX : max(0, (int) $row->quantity_available);
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function computeLine(
        OrderDomainObject $order,
        EventDomainObject $event,
        ProductDomainObject $product,
        Collection $orderItems,
        Collection $protectionProducts,
        AvailableProductQuantitiesResponseDTO $availability,
    ): ?array {
        $price = $this->findOfferPrice($product, $availability);
        $ticketQuantity = $this->getTicketQuantity($orderItems, $protectionProducts);

        if ($price === null || $ticketQuantity <= 0) {
            return null;
        }

        $quantity = $this->isPerTicket($product) ? $ticketQuantity : $product->getRequiredQuantity();
        $quantity = min($quantity, $this->getQuantityAvailable($product->getId(), $price->getId(), $availability));

        if ($quantity <= 0) {
            return null;
        }

        $line = $this->orderItemProcessingService->calculateItemForProduct(
            order: $order,
            product: $product,
            productPrice: new OrderProductPriceDTO(quantity: $quantity, price_id: $price->getId()),
            event: $event,
            promoCode: $this->getPromoCode($order),
        );

        $line['quantity'] = $quantity;

        return $line;
    }

    /**
     * Same count as the required products check: tickets that are neither the protection nor a required product.
     *
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function getTicketQuantity(Collection $orderItems, Collection $protectionProducts): int
    {
        $ticketItems = $orderItems->filter(fn (OrderItemDomainObject $item) => $item->getProductType() === ProductType::TICKET->name
            && ! $protectionProducts->contains(fn (ProductDomainObject $product) => $product->getId() === $item->getProductId()));

        if ($ticketItems->isEmpty()) {
            return 0;
        }

        $requiredProductIds = $this->productRepository
            ->findWhereIn(ProductDomainObjectAbstract::ID, $ticketItems->map(fn (OrderItemDomainObject $item) => $item->getProductId())->unique()->values()->all())
            ->filter(fn (ProductDomainObject $product) => $product->getIsRequired())
            ->map(fn (ProductDomainObject $product) => $product->getId())
            ->all();

        return (int) $ticketItems
            ->reject(fn (OrderItemDomainObject $item) => in_array($item->getProductId(), $requiredProductIds, true))
            ->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function hasPaidItems(Collection $orderItems, Collection $protectionProducts): bool
    {
        return $orderItems->contains(fn (OrderItemDomainObject $item) => (float) $item->getTotalBeforeAdditions() > 0
            && ! $protectionProducts->contains(fn (ProductDomainObject $product) => $product->getId() === $item->getProductId()));
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     * @return Collection<int, OrderItemDomainObject>
     */
    private function getProtectionItems(Collection $orderItems, Collection $protectionProducts): Collection
    {
        return $orderItems->filter(fn (OrderItemDomainObject $item) => $protectionProducts
            ->contains(fn (ProductDomainObject $product) => $product->getId() === $item->getProductId()))
            ->values();
    }

    /**
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function removeProtectionItems(OrderDomainObject $order, Collection $protectionProducts): void
    {
        $this->orderItemRepository->forceDeleteForOrderAndProducts(
            $order->getId(),
            $protectionProducts->map(fn (ProductDomainObject $product) => $product->getId())->values()->all(),
        );
    }

    /**
     * @return Collection<int, OrderItemDomainObject>
     */
    private function getOrderItems(int $orderId): Collection
    {
        return $this->orderItemRepository->findWhere([OrderItemDomainObjectAbstract::ORDER_ID => $orderId]);
    }

    private function refreshTotals(OrderDomainObject $order): OrderDomainObject
    {
        return $this->orderManagementService->updateOrderTotals(
            $order,
            $this->getOrderItems($order->getId()),
        );
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $items
     */
    private function buildOfferFromItems(ProductDomainObject $product, Collection $items, OrderDomainObject $order): RefundProtectionOfferDTO
    {
        return new RefundProtectionOfferDTO(
            product_id: $product->getId(),
            title: $product->getTitle(),
            description: $this->getPlainDescription($product),
            quantity: (int) $items->sum(fn (OrderItemDomainObject $item) => $item->getQuantity()),
            is_per_ticket: $this->isPerTicket($product),
            total_before_additions: Currency::round((float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalBeforeAdditions())),
            total_tax: Currency::round((float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalTax())),
            total_fee: Currency::round((float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalServiceFee())),
            total_gross: Currency::round((float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalGross())),
            currency: $order->getCurrency(),
            is_selected: true,
            is_locked: $product->getIsRequired() === true,
        );
    }

    private function isPerTicket(ProductDomainObject $product): bool
    {
        return $product->getRequiredQuantityMode() === RequiredQuantityMode::PER_TICKET->name;
    }

    private function getPromoCode(OrderDomainObject $order): ?PromoCodeDomainObject
    {
        if ($order->getPromoCodeId() === null) {
            return null;
        }

        return $this->promoCodeRepository->findFirst($order->getPromoCodeId());
    }

    private function getAvailability(int $eventId): AvailableProductQuantitiesResponseDTO
    {
        return $this->availableQuantitiesFetchService->getAvailableProductQuantities($eventId, ignoreCache: true);
    }

    private function getPlainDescription(ProductDomainObject $product): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
            str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', (string) $product->getDescription())
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : Str::limit($text, 280);
    }
}
