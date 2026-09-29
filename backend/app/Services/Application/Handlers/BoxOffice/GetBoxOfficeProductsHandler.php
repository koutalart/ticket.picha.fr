<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — the sellable catalogue for the Kiosk
 * "Vente" screen, scoped to a box office operator's event. Returns only
 * TICKET products, each annotated server-side with:
 *   - is_scannable: attached to at least one active check-in list (the same
 *     rule CreateBoxOfficeSaleHandler::validateScannable() enforces);
 *   - quantity_remaining per price (null = unlimited).
 *
 * The native GET /events/{id}/products is ORGANIZER-gated and would 403 for
 * an operator — this endpoint is authorised by validateBoxOfficeEventScope().
 */
class GetBoxOfficeProductsHandler
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * @return Collection<ProductDomainObject>
     */
    public function handle(int $eventId): Collection
    {
        $products = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhere([
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
            ]);

        return $products->map(function (ProductDomainObject $product) {
            $product->setIsScannable($this->productRepository->hasActiveCheckInList($product->getId()));

            foreach ($product->getProductPrices() ?? [] as $price) {
                $remaining = $this->productRepository->getQuantityRemainingForProductPrice(
                    $product->getId(),
                    $price->getId(),
                );

                $price->setQuantityRemaining($remaining >= Constants::INFINITE ? null : $remaining);
            }

            return $product;
        });
    }
}
