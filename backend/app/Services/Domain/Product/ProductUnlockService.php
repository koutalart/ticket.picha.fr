<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Product;

use HiEvents\Constants;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\InvalidProductUnlockRuleException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use Illuminate\Support\Collection;

/**
 * A product with unlock_after_product_id stays locked (treated as hidden online
 * and at the box office) until the referenced product is sold out, using the
 * same availability figures as the public ticket page.
 */
class ProductUnlockService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AvailableProductQuantitiesFetchService $availableQuantitiesFetchService,
    ) {}

    /**
     * @param  Collection<ProductDomainObject>  $products
     * @return int[]
     */
    public function getLockedProductIds(Collection $products, AvailableProductQuantitiesResponseDTO $quantities): array
    {
        return $products
            ->filter(fn (ProductDomainObject $product) => $product->getUnlockAfterProductId() !== null
                && ! $this->isProductSoldOut($product->getUnlockAfterProductId(), $quantities->productQuantities))
            ->map(fn (ProductDomainObject $product) => $product->getId())
            ->values()
            ->all();
    }

    /**
     * Fetches live (uncached) quantities only when at least one product carries an unlock rule.
     *
     * @param  Collection<ProductDomainObject>  $products
     * @return int[]
     */
    public function getLockedProductIdsForEvent(int $eventId, Collection $products): array
    {
        if (! $this->hasUnlockRules($products)) {
            return [];
        }

        return $this->getLockedProductIds(
            $products,
            $this->availableQuantitiesFetchService->getAvailableProductQuantities($eventId, ignoreCache: true),
        );
    }

    /**
     * @param  Collection<ProductDomainObject>  $products
     */
    public function hasUnlockRules(Collection $products): bool
    {
        return $products->contains(fn (ProductDomainObject $product) => $product->getUnlockAfterProductId() !== null);
    }

    /**
     * Mirrors ProductDomainObject::isSoldOut() as computed by ProductFilterService:
     * every price has no quantity left (sold + active reservations + capacity assignments).
     * A product with no remaining price rows (e.g. deleted) counts as sold out.
     *
     * @param  Collection<AvailableProductQuantitiesDTO>  $productQuantities
     */
    public function isProductSoldOut(int $productId, Collection $productQuantities): bool
    {
        $rows = $productQuantities->filter(
            fn (AvailableProductQuantitiesDTO $row) => $row->product_id === $productId
        );

        if ($rows->isEmpty()) {
            return true;
        }

        return $rows->every(
            fn (AvailableProductQuantitiesDTO $row) => $row->quantity_available !== Constants::INFINITE
                && $row->quantity_available <= 0
        );
    }

    /**
     * @throws InvalidProductUnlockRuleException
     */
    public function validateUnlockRule(int $eventId, ?int $productId, ?int $unlockAfterProductId): void
    {
        if ($unlockAfterProductId === null) {
            return;
        }

        if ($productId !== null && $unlockAfterProductId === $productId) {
            throw new InvalidProductUnlockRuleException(
                __('A ticket cannot be opened by its own sale.')
            );
        }

        /** @var ProductDomainObject|null $source */
        $source = $this->productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => $unlockAfterProductId,
            ProductDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($source === null) {
            throw new InvalidProductUnlockRuleException(
                __('The ticket that opens this one must belong to the same event.')
            );
        }

        if ($productId !== null && $source->getUnlockAfterProductId() === $productId) {
            throw new InvalidProductUnlockRuleException(
                __('These two tickets cannot open each other.')
            );
        }
    }
}
