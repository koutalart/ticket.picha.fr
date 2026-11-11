<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\ProductNotEditableByAssistantException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DTO\SetProductSalesPausedDTO;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\ProductEvent;

/**
 * Opens or closes the sale of one product, the same switch as "Pause sales" in the dashboard.
 * The cancellation protection is a platform fee: it is never changed here.
 */
class SetProductSalesPausedHandler
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws ProductNotEditableByAssistantException
     */
    public function handle(SetProductSalesPausedDTO $dto): ProductDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => $dto->productId,
            ProductDomainObjectAbstract::EVENT_ID => $dto->eventId,
        ]);

        if ($product === null) {
            throw new ResourceNotFoundException(__('Product :id not found for this event.', ['id' => $dto->productId]));
        }

        if ($product->getIsRefundProtection()) {
            throw new ProductNotEditableByAssistantException(__('The cancellation protection cannot be changed here.'));
        }

        $this->productRepository->updateWhere(
            attributes: [ProductDomainObjectAbstract::IS_SALES_PAUSED => $dto->salesPaused],
            where: [
                ProductDomainObjectAbstract::ID => $dto->productId,
                ProductDomainObjectAbstract::EVENT_ID => $dto->eventId,
            ],
        );

        $this->domainEventDispatcherService->dispatch(
            new ProductEvent(type: DomainEventType::PRODUCT_UPDATED, productId: $dto->productId)
        );

        return $this->productRepository->findById($dto->productId);
    }
}
