<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\RefundProtection;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
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
use HiEvents\Services\Domain\Order\RefundProtection\DTO\RefundProtectionPlanDTO;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\DTO\OrderProductPriceDTO;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The refund protection (is_refund_protection, not a ticket) is never picked in the product list.
 *
 * - Mandatory part: one protection per paid ticket whose tariff price is above the platform threshold
 *   (RefundProtectionPolicy), or the quantity set by the organizer when the protection is is_required.
 *   It is added by the server and cannot be removed.
 * - Optional part: the checkbox right before payment, for the other tickets of the order
 *   (one per order, or one per remaining ticket in PER_TICKET mode).
 *
 * Both parts are stored on a single order line. The split is saved in the order point_in_time_data
 * (key refund_protection) so the live chat refund knows which tickets are covered.
 */
class OrderRefundProtectionService
{
    public const SNAPSHOT_KEY = 'refund_protection';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly OrderItemProcessingService $orderItemProcessingService,
        private readonly OrderManagementService $orderManagementService,
        private readonly AvailableProductQuantitiesFetchService $availableQuantitiesFetchService,
        private readonly ProductUnlockService $productUnlockService,
        private readonly RefundProtectionPolicy $policy,
    ) {}

    public static function isCheckoutRefundProtection(ProductDomainObject $product): bool
    {
        return RefundProtectionPolicy::isCheckoutRefundProtection($product);
    }

    public function getOffer(OrderDomainObject $order, EventDomainObject $event): ?RefundProtectionOfferDTO
    {
        if ($event->getEventSettings()?->getSingleProductPerOrder()) {
            return null;
        }

        $orderItems = $this->getOrderItems($order->getId());
        $protectionProducts = $this->getProtectionProducts($event->getId());
        $selectedItems = $this->getProtectionItems($orderItems, $protectionProducts);
        $availability = $this->getAvailability($event->getId());

        if ($selectedItems->isNotEmpty()) {
            $product = $protectionProducts->first(
                fn (ProductDomainObject $product) => $product->getId() === $selectedItems->first()->getProductId()
            );
        } else {
            $product = $this->findOfferProduct($protectionProducts, $availability);
        }

        if ($product === null) {
            return null;
        }

        $plan = $this->buildPlan($product, $orderItems, $protectionProducts);

        if ($selectedItems->isEmpty() && $plan->isEmpty()) {
            return null;
        }

        if ($plan->is_required_by_organizer) {
            return $selectedItems->isNotEmpty()
                ? $this->buildOfferFromItems($product, $selectedItems, $order, $plan, isLocked: true)
                : $this->buildComputedOffer($order, $event, $product, $plan, $plan->mandatory_quantity, isSelected: false, isLocked: false);
        }

        if ($plan->mandatory_quantity > 0 && $plan->optional_quantity <= 0) {
            return $selectedItems->isNotEmpty()
                ? $this->buildOfferFromItems($product, $selectedItems, $order, $plan, isLocked: true)
                : $this->buildComputedOffer($order, $event, $product, $plan, $plan->mandatory_quantity, isSelected: false, isLocked: true);
        }

        if ($plan->mandatory_quantity <= 0) {
            return $selectedItems->isNotEmpty()
                ? $this->buildOfferFromItems($product, $selectedItems, $order, $plan, isLocked: false)
                : $this->buildComputedOffer($order, $event, $product, $plan, $plan->optional_quantity, isSelected: false, isLocked: false);
        }

        $selectedQuantity = (int) $selectedItems->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());

        return $this->buildMixedOffer($order, $event, $product, $plan, isSelected: $selectedQuantity > $plan->mandatory_quantity);
    }

    /**
     * Adds (enabled) or removes the optional part of the protection and recalculates the order totals.
     * The mandatory part always stays.
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
        }

        $this->removeProtectionItems($order, $protectionProducts);
        $orderItems = $this->getOrderItems($order->getId());

        $availability = $this->getAvailability($event->getId());
        $product = $this->findOfferProduct($protectionProducts, $availability);
        $plan = $product !== null ? $this->buildPlan($product, $orderItems, $protectionProducts) : null;

        if (! $enabled) {
            $mandatoryQuantity = $plan !== null && ! $plan->is_required_by_organizer ? $plan->mandatory_quantity : 0;

            if ($mandatoryQuantity <= 0) {
                if ($selectedItems->isEmpty()) {
                    return $order;
                }

                return $this->saveSnapshot($this->refreshTotals($order), null, false);
            }

            return $this->addLine($order, $event, $product, $plan, $mandatoryQuantity, optionalSelected: false);
        }

        if ($plan === null) {
            throw new RefundProtectionUnavailableException(__('The cancellation protection is not available for this order.'));
        }

        $quantity = $plan->is_required_by_organizer
            ? $plan->mandatory_quantity
            : $plan->mandatory_quantity + $plan->optional_quantity;

        return $this->addLine($order, $event, $product, $plan, $quantity, optionalSelected: $plan->optional_quantity > 0);
    }

    /**
     * Run once the order lines are saved at creation: a mandatory protection is added automatically,
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

        if (! $hasSelectedItems && ! $isRequired && ! $this->hasMandatoryTickets($orderItems, $protectionProducts, $event)) {
            return $order;
        }

        try {
            return $this->apply($order, $event, $hasSelectedItems || $isRequired);
        } catch (RefundProtectionUnavailableException) {
            $this->removeProtectionItems($order, $protectionProducts);

            return $this->saveSnapshot($this->refreshTotals($order), null, false);
        }
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function hasMandatoryTickets(Collection $orderItems, Collection $protectionProducts, EventDomainObject $event): bool
    {
        $product = $this->findOfferProduct($protectionProducts, $this->getAvailability($event->getId()));

        return $product !== null && $this->buildPlan($product, $orderItems, $protectionProducts)->mandatory_quantity > 0;
    }

    /**
     * Tickets counted like the required products check: tickets that are neither the protection nor a required product.
     *
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     */
    private function buildPlan(ProductDomainObject $product, Collection $orderItems, Collection $protectionProducts): RefundProtectionPlanDTO
    {
        $ticketItems = $orderItems->filter(fn (OrderItemDomainObject $item) => $item->getProductType() === ProductType::TICKET->name
            && ! $protectionProducts->contains(fn (ProductDomainObject $protection) => $protection->getId() === $item->getProductId()));

        $ticketProducts = $ticketItems->isEmpty()
            ? collect()
            : $this->productRepository
                ->findWhereIn(ProductDomainObjectAbstract::ID, $ticketItems->map(fn (OrderItemDomainObject $item) => $item->getProductId())->unique()->values()->all())
                ->keyBy(fn (ProductDomainObject $ticket) => $ticket->getId());

        $ticketItems = $ticketItems
            ->reject(fn (OrderItemDomainObject $item) => $ticketProducts->get($item->getProductId())?->getIsRequired() === true)
            ->values();

        $ticketQuantity = (int) $ticketItems->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());

        if ($product->getIsRequired()) {
            $quantity = RefundProtectionPolicy::isPerTicket($product) ? $ticketQuantity : $product->getRequiredQuantity();

            return new RefundProtectionPlanDTO(
                ticket_quantity: $ticketQuantity,
                mandatory_quantity: $ticketQuantity > 0 ? $quantity : 0,
                optional_quantity: 0,
                is_required_by_organizer: true,
                mandatory_above: null,
            );
        }

        $isAbove = fn (OrderItemDomainObject $item) => $ticketProducts->has($item->getProductId())
            && $this->policy->isTicketAboveThreshold(
                $ticketProducts->get($item->getProductId()),
                (float) ($item->getPriceBeforeDiscount() ?? $item->getPrice()),
                (float) $item->getPrice(),
            );

        $mandatoryQuantity = (int) $ticketItems->filter($isAbove)->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());
        $otherTicketQuantity = $ticketQuantity - $mandatoryQuantity;

        $hasOptionalCandidate = $mandatoryQuantity > 0
            ? $ticketItems->reject($isAbove)->contains(fn (OrderItemDomainObject $item) => (float) $item->getPrice() > 0)
            : $this->hasPaidItems($orderItems, $protectionProducts);

        $optionalQuantity = $otherTicketQuantity > 0 && $hasOptionalCandidate
            ? (RefundProtectionPolicy::isPerTicket($product) ? $otherTicketQuantity : $product->getRequiredQuantity())
            : 0;

        return new RefundProtectionPlanDTO(
            ticket_quantity: $ticketQuantity,
            mandatory_quantity: $mandatoryQuantity,
            optional_quantity: $optionalQuantity,
            is_required_by_organizer: false,
            mandatory_above: $this->policy->getMandatoryAbove(),
        );
    }

    /**
     * @throws RefundProtectionUnavailableException
     */
    private function addLine(
        OrderDomainObject $order,
        EventDomainObject $event,
        ProductDomainObject $product,
        RefundProtectionPlanDTO $plan,
        int $quantity,
        bool $optionalSelected,
    ): OrderDomainObject {
        $line = $quantity > 0 ? $this->computeLine($order, $event, $product, $quantity, capByStock: true) : null;

        if ($line === null) {
            throw new RefundProtectionUnavailableException(__('The cancellation protection is not available for this order.'));
        }

        $this->orderRepository->addOrderItem($line);

        return $this->saveSnapshot($this->refreshTotals($order), $plan, $optionalSelected);
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
        return $this->policy->findOfferProduct(
            $protectionProducts,
            $availability,
            $this->productUnlockService->getLockedProductIds($protectionProducts, $availability),
        );
    }

    private function computeLine(
        OrderDomainObject $order,
        EventDomainObject $event,
        ProductDomainObject $product,
        int $quantity,
        bool $capByStock,
    ): ?array {
        $availability = $this->getAvailability($event->getId());
        $price = $this->policy->findOfferPrice($product, $availability);

        if ($price === null || $quantity <= 0) {
            return null;
        }

        if ($capByStock) {
            $quantity = min($quantity, $this->policy->getQuantityAvailable($product->getId(), $price->getId(), $availability));
        }

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
     * Only the platform rule (threshold) needs a snapshot: a protection the organizer made required keeps the previous coverage rules.
     */
    private function saveSnapshot(OrderDomainObject $order, ?RefundProtectionPlanDTO $plan, bool $optionalSelected): OrderDomainObject
    {
        $data = is_array($order->getPointInTimeData()) ? $order->getPointInTimeData() : [];
        unset($data[self::SNAPSHOT_KEY]);

        if ($plan !== null && ! $plan->is_required_by_organizer) {
            $data[self::SNAPSHOT_KEY] = [
                'mandatory_quantity' => $plan->mandatory_quantity,
                'optional_selected' => $optionalSelected,
                'mandatory_above' => $plan->mandatory_above,
            ];
        }

        if ($data === (is_array($order->getPointInTimeData()) ? $order->getPointInTimeData() : [])) {
            return $order;
        }

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::POINT_IN_TIME_DATA => $data === [] ? null : $data,
        ]);

        return $order->setPointInTimeData($data === [] ? null : $data);
    }

    private function buildComputedOffer(
        OrderDomainObject $order,
        EventDomainObject $event,
        ProductDomainObject $product,
        RefundProtectionPlanDTO $plan,
        int $quantity,
        bool $isSelected,
        bool $isLocked,
    ): ?RefundProtectionOfferDTO {
        $line = $this->computeLine($order, $event, $product, $quantity, capByStock: true);

        if ($line === null) {
            return null;
        }

        return $this->makeOffer(
            product: $product,
            order: $order,
            plan: $plan,
            quantity: $line['quantity'],
            beforeAdditions: (float) $line['total_before_additions'],
            tax: (float) $line['total_tax'],
            fee: (float) $line['total_service_fee'],
            gross: (float) $line['total_gross'],
            isSelected: $isSelected,
            isLocked: $isLocked,
            mandatoryTotalGross: $plan->mandatory_quantity > 0 && $isLocked ? (float) $line['total_gross'] : 0.0,
        );
    }

    /**
     * Mixed order: the mandatory part is fixed, the checkbox adds (or removes) the optional part.
     * The optional amounts are the difference between the full line and the mandatory line, so they add up exactly.
     */
    private function buildMixedOffer(
        OrderDomainObject $order,
        EventDomainObject $event,
        ProductDomainObject $product,
        RefundProtectionPlanDTO $plan,
        bool $isSelected,
    ): ?RefundProtectionOfferDTO {
        $mandatory = $this->computeLine($order, $event, $product, $plan->mandatory_quantity, capByStock: false);
        $full = $this->computeLine($order, $event, $product, $plan->mandatory_quantity + $plan->optional_quantity, capByStock: false);

        if ($mandatory === null || $full === null) {
            return null;
        }

        return $this->makeOffer(
            product: $product,
            order: $order,
            plan: $plan,
            quantity: $plan->optional_quantity,
            beforeAdditions: (float) $full['total_before_additions'] - (float) $mandatory['total_before_additions'],
            tax: (float) $full['total_tax'] - (float) $mandatory['total_tax'],
            fee: (float) $full['total_service_fee'] - (float) $mandatory['total_service_fee'],
            gross: (float) $full['total_gross'] - (float) $mandatory['total_gross'],
            isSelected: $isSelected,
            isLocked: false,
            mandatoryTotalGross: (float) $mandatory['total_gross'],
        );
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $items
     */
    private function buildOfferFromItems(
        ProductDomainObject $product,
        Collection $items,
        OrderDomainObject $order,
        RefundProtectionPlanDTO $plan,
        bool $isLocked,
    ): RefundProtectionOfferDTO {
        $gross = (float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalGross());

        return $this->makeOffer(
            product: $product,
            order: $order,
            plan: $plan,
            quantity: (int) $items->sum(fn (OrderItemDomainObject $item) => $item->getQuantity()),
            beforeAdditions: (float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalBeforeAdditions()),
            tax: (float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalTax()),
            fee: (float) $items->sum(fn (OrderItemDomainObject $item) => $item->getTotalServiceFee()),
            gross: $gross,
            isSelected: true,
            isLocked: $isLocked,
            mandatoryTotalGross: $isLocked && ! $plan->is_required_by_organizer ? $gross : 0.0,
        );
    }

    private function makeOffer(
        ProductDomainObject $product,
        OrderDomainObject $order,
        RefundProtectionPlanDTO $plan,
        int $quantity,
        float $beforeAdditions,
        float $tax,
        float $fee,
        float $gross,
        bool $isSelected,
        bool $isLocked,
        float $mandatoryTotalGross,
    ): RefundProtectionOfferDTO {
        return new RefundProtectionOfferDTO(
            product_id: $product->getId(),
            title: $product->getTitle(),
            description: $this->getPlainDescription($product),
            quantity: $quantity,
            is_per_ticket: RefundProtectionPolicy::isPerTicket($product),
            total_before_additions: Currency::round($beforeAdditions),
            total_tax: Currency::round($tax),
            total_fee: Currency::round($fee),
            total_gross: Currency::round($gross),
            currency: $order->getCurrency(),
            is_selected: $isSelected,
            is_locked: $isLocked,
            mandatory_quantity: $plan->is_required_by_organizer ? 0 : $plan->mandatory_quantity,
            mandatory_total_gross: Currency::round($mandatoryTotalGross),
            mandatory_above: $plan->is_required_by_organizer ? null : $plan->mandatory_above,
            is_required_by_organizer: $plan->is_required_by_organizer,
        );
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
