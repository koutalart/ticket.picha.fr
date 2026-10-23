<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\Generated\ProductPriceDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\InvalidProductUnlockRuleException;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\CheckInList\DefaultCheckInListService;
use HiEvents\Services\Domain\Product\CreateProductService;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use HiEvents\Services\Domain\Product\ProductUnlockService;
use HiEvents\Services\Domain\ProductCategory\GetProductCategoryService;
use Illuminate\Database\DatabaseManager;
use Throwable;

class CreateProductHandler
{
    public function __construct(
        private readonly CreateProductService      $productCreateService,
        private readonly GetProductCategoryService $getProductCategoryService,
        private readonly DefaultCheckInListService $defaultCheckInListService,
        private readonly DatabaseManager           $databaseManager,
        private readonly ProductUnlockService      $productUnlockService,
    )
    {
    }

    /**
     * @throws InvalidProductUnlockRuleException
     * @throws Throwable
     */
    public function handle(UpsertProductDTO $productsData): ProductDomainObject
    {
        $this->productUnlockService->validateUnlockRule(
            eventId: $productsData->event_id,
            productId: null,
            unlockAfterProductId: $productsData->unlock_after_product_id,
        );

        return $this->databaseManager->transaction(function () use ($productsData) {
            $product = $this->createProduct($productsData);
            $this->defaultCheckInListService->addTicket($product);

            return $product;
        });
    }

    private function createProduct(UpsertProductDTO $productsData): ProductDomainObject
    {
        $productPrices = $productsData->prices->map(fn(ProductPriceDTO $price) => ProductPriceDomainObject::hydrateFromArray([
            ProductPriceDomainObjectAbstract::PRICE => $productsData->type === ProductPriceType::FREE ? 0.00 : $price->price,
            ProductPriceDomainObjectAbstract::LABEL => $price->label,
            ProductPriceDomainObjectAbstract::SALE_START_DATE => $price->sale_start_date,
            ProductPriceDomainObjectAbstract::SALE_END_DATE => $price->sale_end_date,
            ProductPriceDomainObjectAbstract::INITIAL_QUANTITY_AVAILABLE => $price->initial_quantity_available,
            ProductPriceDomainObjectAbstract::IS_HIDDEN => $price->is_hidden,
        ]));

        $category = $this->getProductCategoryService->getCategory(
            categoryId: $productsData->product_category_id,
            eventId: $productsData->event_id
        );

        return $this->productCreateService->createProduct(
            product: (new ProductDomainObject())
                ->setTitle($productsData->title)
                ->setType($productsData->type->name)
                ->setOrder($productsData->order)
                ->setSaleStartDate($productsData->sale_start_date)
                ->setSaleEndDate($productsData->sale_end_date)
                ->setMaxPerOrder($productsData->max_per_order)
                ->setDescription($productsData->description)
                ->setMinPerOrder($productsData->min_per_order)
                ->setIsHidden($productsData->is_hidden)
                ->setStartCollapsed($productsData->start_collapsed)
                ->setHideBeforeSaleStartDate($productsData->hide_before_sale_start_date)
                ->setHideAfterSaleEndDate($productsData->hide_after_sale_end_date)
                ->setHideWhenSoldOut($productsData->hide_when_sold_out)
                ->setShowQuantityRemaining($productsData->show_quantity_remaining)
                ->setIsHiddenWithoutPromoCode($productsData->is_hidden_without_promo_code)
                ->setIsVisibleAtBoxOffice($productsData->is_visible_at_box_office ?? true)
                ->setIsSalesPaused($productsData->is_sales_paused ?? ($productsData->is_hidden && ($productsData->is_visible_at_box_office ?? true)))
                ->setIsRequired($productsData->is_required ?? false)
                ->setRequiredQuantityMode($productsData->required_quantity_mode ?? RequiredQuantityMode::PER_ORDER->name)
                ->setIsHighlighted($productsData->is_highlighted ?? false)
                ->setHighlightMessage($productsData->highlight_message)
                ->setWaitlistEnabled($productsData->waitlist_enabled)
                ->setUnlockAfterProductId($productsData->unlock_after_product_id)
                ->setProductPrices($productPrices)
                ->setEventId($productsData->event_id)
                ->setProductType($productsData->product_type->name)
                ->setProductCategoryId($category->getId()),
            accountId: $productsData->account_id,
            taxAndFeeIds: $productsData->tax_and_fee_ids,
        );
    }
}
