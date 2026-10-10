<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\CheckInList;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;

/**
 * Every event starts with one check-in list, and each new ticket joins the lists that already
 * cover all the event's tickets. Lists the organizer restricted to some tickets are left alone.
 */
class DefaultCheckInListService
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CheckInListProductAssociationService $checkInListProductAssociationService,
    ) {}

    public function createForEvent(int $eventId): CheckInListDomainObject
    {
        return $this->checkInListRepository->create([
            CheckInListDomainObjectAbstract::NAME => __('Main entrance'),
            CheckInListDomainObjectAbstract::EVENT_ID => $eventId,
            CheckInListDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
        ]);
    }

    public function addTicket(ProductDomainObject $ticket): void
    {
        if ($ticket->getProductType() !== ProductType::TICKET->name) {
            return;
        }

        $eventId = $ticket->getEventId();
        $lists = $this->checkInListRepository
            ->loadRelation(ProductDomainObject::class)
            ->findWhere([CheckInListDomainObjectAbstract::EVENT_ID => $eventId]);

        if ($lists->isEmpty()) {
            $list = $this->createForEvent($eventId);
            $this->checkInListProductAssociationService->addCheckInListToProducts($list->getId(), [$ticket->getId()], false);

            return;
        }

        $otherTicketIds = $this->productRepository
            ->findWhere([
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
            ])
            ->map(fn (ProductDomainObject $product) => $product->getId())
            ->reject(fn (int $id) => $id === $ticket->getId())
            ->values()
            ->all();

        /** @var CheckInListDomainObject $list */
        foreach ($lists as $list) {
            $listProductIds = $list->getProducts()?->map(fn (ProductDomainObject $product) => $product->getId())->all() ?? [];

            if (array_diff($otherTicketIds, $listProductIds) !== []) {
                continue;
            }

            $this->checkInListProductAssociationService->addCheckInListToProducts(
                checkInListId: $list->getId(),
                productIds: [...$listProductIds, $ticket->getId()],
                removePreviousAssignments: false,
            );
        }
    }
}
