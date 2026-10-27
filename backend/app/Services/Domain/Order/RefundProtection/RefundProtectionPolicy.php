<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\RefundProtection;

use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;

/**
 * Platform rules of the cancellation protection, shared by the order (OrderRefundProtectionService)
 * and the public product list (ProductFilterService) so that the displayed price and the charged amount match.
 *
 * A paid ticket whose tariff price is strictly above app.refund_protection_mandatory_above makes the protection
 * mandatory for that ticket (one per ticket). A protection the organizer made required keeps its own settings.
 */
class RefundProtectionPolicy
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public static function isCheckoutRefundProtection(ProductDomainObject $product): bool
    {
        return $product->getIsRefundProtection() === true
            && $product->getProductType() !== ProductType::TICKET->name;
    }

    public static function isPerTicket(ProductDomainObject $product): bool
    {
        return $product->getRequiredQuantityMode() === RequiredQuantityMode::PER_TICKET->name;
    }

    public function getMandatoryAbove(): ?float
    {
        $value = $this->config->get('app.refund_protection_mandatory_above');

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * True when this ticket line needs one mandatory protection per ticket because of its price.
     * The tariff price is the price before any promo code; the ticket must still be paid after the promo code.
     */
    public function isTicketAboveThreshold(ProductDomainObject $ticket, float $tariffPrice, float $paidPrice): bool
    {
        $threshold = $this->getMandatoryAbove();

        return $threshold !== null
            && $ticket->getProductType() === ProductType::TICKET->name
            && $ticket->getIsRefundProtection() !== true
            && $ticket->getIsRequired() !== true
            && in_array($ticket->getType(), [ProductPriceType::PAID->name, ProductPriceType::TIERED->name], true)
            && $paidPrice > 0
            && (int) round($tariffPrice * 100) > (int) round($threshold * 100);
    }

    /**
     * True when the protection is added once per ticket of this ticket line, so its unit price is part of the ticket price.
     */
    public function isIncludedInTicketPrice(
        ProductDomainObject $protection,
        ProductDomainObject $ticket,
        float $tariffPrice,
        float $paidPrice,
    ): bool {
        if ($ticket->getProductType() !== ProductType::TICKET->name
            || $ticket->getIsRefundProtection() === true
            || $ticket->getIsRequired() === true) {
            return false;
        }

        if ($protection->getIsRequired() === true) {
            return self::isPerTicket($protection);
        }

        return $this->isTicketAboveThreshold($ticket, $tariffPrice, $paidPrice);
    }

    /**
     * @param  Collection<int, ProductDomainObject>  $protectionProducts
     * @param  int[]  $lockedProductIds
     */
    public function findOfferProduct(
        Collection $protectionProducts,
        AvailableProductQuantitiesResponseDTO $availability,
        array $lockedProductIds,
    ): ?ProductDomainObject {
        return $protectionProducts
            ->filter(fn (ProductDomainObject $product) => self::isCheckoutRefundProtection($product))
            ->sortBy(fn (ProductDomainObject $product) => [(int) $product->getOrder(), $product->getId()])
            ->first(fn (ProductDomainObject $product) => ! $product->getIsHidden()
                && ! $product->getIsSalesPaused()
                && ! $product->getIsHiddenWithoutPromoCode()
                && ! $product->isBeforeSaleStartDate()
                && ! $product->isAfterSaleEndDate()
                && in_array($product->getType(), [ProductPriceType::PAID->name, ProductPriceType::TIERED->name], true)
                && ! in_array($product->getId(), $lockedProductIds, true)
                && $this->findOfferPrice($product, $availability) !== null
            );
    }

    public function findOfferPrice(ProductDomainObject $product, AvailableProductQuantitiesResponseDTO $availability): ?ProductPriceDomainObject
    {
        return $product->getProductPrices()
            ?->sortBy(fn (ProductPriceDomainObject $price) => [(int) $price->getOrder(), $price->getId()])
            ->first(fn (ProductPriceDomainObject $price) => ! $price->getIsHidden()
                && ! $price->isBeforeSaleStartDate()
                && ! $price->isAfterSaleEndDate()
                && (float) ($price->getPriceBeforeDiscount() ?? $price->getPrice()) > 0
                && $this->getQuantityAvailable($product->getId(), $price->getId(), $availability) > 0
            );
    }

    public function getQuantityAvailable(int $productId, int $priceId, AvailableProductQuantitiesResponseDTO $availability): int
    {
        $row = $availability->productQuantities
            ->first(fn ($row) => $row->product_id === $productId && $row->price_id === $priceId);

        if ($row === null) {
            return 0;
        }

        return $row->quantity_available === Constants::INFINITE ? PHP_INT_MAX : max(0, (int) $row->quantity_available);
    }
}
