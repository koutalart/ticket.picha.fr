<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Public;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Domain\Order\RefundProtection\DTO\RefundProtectionOfferDTO;
use HiEvents\Services\Domain\Order\RefundProtection\OrderRefundProtectionService;

class GetOrderRefundProtectionPublicHandler
{
    public function __construct(
        private readonly RefundProtectionOrderLoader $orderLoader,
        private readonly OrderRefundProtectionService $refundProtectionService,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws UnauthorizedException
     */
    public function handle(int $eventId, string $orderShortId): ?RefundProtectionOfferDTO
    {
        $order = $this->orderLoader->loadOrder($eventId, $orderShortId);

        return $this->refundProtectionService->getOffer($order, $this->orderLoader->loadEvent($eventId));
    }
}
