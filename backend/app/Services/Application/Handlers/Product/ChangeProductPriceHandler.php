<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductPriceDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\ProductNotEditableByAssistantException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DTO\ChangeProductPriceDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\ProductEvent;
use Illuminate\Database\DatabaseManager;

/**
 * Changes the price of one product price, without the service fees: the platform fee stays paid by the buyer
 * and is computed at checkout. Free products and the cancellation protection are never changed here.
 */
class ChangeProductPriceHandler
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductPriceRepositoryInterface $productPriceRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws ProductNotEditableByAssistantException
     */
    public function handle(ChangeProductPriceDTO $dto): ProductPriceDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => $dto->productId,
            ProductDomainObjectAbstract::EVENT_ID => $dto->eventId,
        ]);

        if ($product === null) {
            throw new ResourceNotFoundException(__('Product :id not found for this event.', ['id' => $dto->productId]));
        }

        $this->assertPriceCanChange($product, $dto->price);

        $price = $this->databaseManager->transaction(function () use ($dto) {
            /** @var ProductPriceDomainObject|null $productPrice */
            $productPrice = $this->productPriceRepository->lockForUpdateById($dto->productPriceId);

            if ($productPrice === null || $productPrice->getProductId() !== $dto->productId) {
                throw new ResourceNotFoundException(__('Price :id not found for this product.', ['id' => $dto->productPriceId]));
            }

            $this->productPriceRepository->updateWhere(
                attributes: [ProductPriceDomainObjectAbstract::PRICE => Currency::round($dto->price)],
                where: [
                    ProductPriceDomainObjectAbstract::ID => $dto->productPriceId,
                    ProductPriceDomainObjectAbstract::PRODUCT_ID => $dto->productId,
                ],
            );

            return $this->productPriceRepository->findById($dto->productPriceId);
        });

        $this->domainEventDispatcherService->dispatch(
            new ProductEvent(type: DomainEventType::PRODUCT_UPDATED, productId: $dto->productId)
        );

        return $price;
    }

    /**
     * @throws ProductNotEditableByAssistantException
     */
    private function assertPriceCanChange(ProductDomainObject $product, float $price): void
    {
        if ($product->getIsRefundProtection()) {
            throw new ProductNotEditableByAssistantException(__('The cancellation protection cannot be changed here.'));
        }

        $type = $product->getType();

        if (in_array($type, [ProductPriceType::FREE->name, ProductPriceType::REGISTRATION->name], true)) {
            throw new ProductNotEditableByAssistantException(__('This product is free: its price cannot be changed here.'));
        }

        if ($price < 0 || ($price <= 0 && $type !== ProductPriceType::DONATION->name)) {
            throw new ProductNotEditableByAssistantException(__('The new price must be greater than zero.'));
        }
    }
}
