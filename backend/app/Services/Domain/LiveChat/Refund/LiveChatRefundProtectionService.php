<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;

/**
 * Whether the web chat may offer an automatic refund: the organizer sells a cancellation protection product
 * on at least one event that has not started yet.
 */
class LiveChatRefundProtectionService
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    public function isAvailableForOrganizer(int $organizerId): bool
    {
        $eventIds = $this->eventRepository->findWhere([
            EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            [EventDomainObjectAbstract::START_DATE, '>', now()->toDateTimeString()],
        ])->map(fn ($event) => $event->getId())->all();

        if ($eventIds === []) {
            return false;
        }

        return $this->productRepository->countWhere([
            [ProductDomainObjectAbstract::EVENT_ID, 'in', $eventIds],
            ProductDomainObjectAbstract::IS_REFUND_PROTECTION => true,
        ]) > 0;
    }
}
