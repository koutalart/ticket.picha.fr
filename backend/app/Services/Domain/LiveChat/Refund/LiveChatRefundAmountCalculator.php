<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\LiveChatRefundSelectionException;
use HiEvents\Services\Domain\Order\RefundProtection\OrderRefundProtectionService;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundCoverageDTO;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundTicketDTO;
use Illuminate\Support\Collection;

/**
 * Only the ticket price is refunded: never the service fees (platform fee included), never the taxes on fees,
 * never the protection product itself. The amount always comes from the order, never from the visitor.
 */
class LiveChatRefundAmountCalculator
{
    /**
     * @param  Collection<int, OrderItemDomainObject>  $orderItems
     * @param  Collection<int, ProductDomainObject>  $products  keyed by product id
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     */
    public function coverage(Collection $orderItems, Collection $products, Collection $attendees): LiveChatRefundCoverageDTO
    {
        $protectionItems = $orderItems->filter(
            fn (OrderItemDomainObject $item) => $products->get($item->getProductId())?->getIsRefundProtection() === true
                && $item->getQuantity() > 0,
        );

        if ($protectionItems->isEmpty()) {
            return new LiveChatRefundCoverageDTO(hasProtection: false, tickets: collect(), maxTickets: 0);
        }

        $itemsByPrice = $orderItems->keyBy(fn (OrderItemDomainObject $item) => $item->getProductPriceId());

        $tickets = $attendees
            ->filter(function (AttendeeDomainObject $attendee) use ($products) {
                $product = $products->get($attendee->getProductId());

                return $attendee->getStatus() === AttendeeStatus::ACTIVE->name
                    && $product !== null
                    && ! $product->getIsRefundProtection()
                    && $product->getProductType() === ProductType::TICKET->name;
            })
            ->map(function (AttendeeDomainObject $attendee) use ($itemsByPrice) {
                /** @var OrderItemDomainObject|null $item */
                $item = $itemsByPrice->get($attendee->getProductPriceId());
                if ($item === null || $item->getProductId() !== $attendee->getProductId()) {
                    return null;
                }

                return new LiveChatRefundTicketDTO(
                    attendeeId: $attendee->getId(),
                    name: trim($attendee->getFirstName().' '.$attendee->getLastName()),
                    category: (string) $item->getItemName(),
                    amount: $this->fromCents($this->toCents((float) $item->getPrice())),
                );
            })
            ->filter(fn (?LiveChatRefundTicketDTO $ticket) => $ticket !== null && $ticket->amount > 0)
            ->sortBy(fn (LiveChatRefundTicketDTO $ticket) => $ticket->attendeeId)
            ->values();

        $coversWholeOrder = $protectionItems->contains(function (OrderItemDomainObject $item) use ($products) {
            $product = $products->get($item->getProductId());

            return $product->getRequiredQuantityMode() === RequiredQuantityMode::PER_ORDER->name
                && ($product->getIsRequired() || OrderRefundProtectionService::isCheckoutRefundProtection($product));
        });

        $protectedCount = (int) $protectionItems->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());

        return new LiveChatRefundCoverageDTO(
            hasProtection: true,
            tickets: $tickets,
            maxTickets: $coversWholeOrder ? $tickets->count() : min($protectedCount, $tickets->count()),
        );
    }

    /**
     * @param  int[]  $attendeeIds
     * @return Collection<int, LiveChatRefundTicketDTO>
     *
     * @throws LiveChatRefundSelectionException
     */
    public function select(LiveChatRefundCoverageDTO $coverage, array $attendeeIds): Collection
    {
        $attendeeIds = array_values(array_unique(array_map('intval', $attendeeIds)));
        $selected = $coverage->tickets->filter(
            fn (LiveChatRefundTicketDTO $ticket) => in_array($ticket->attendeeId, $attendeeIds, true),
        )->values();

        if ($attendeeIds === [] || $selected->count() !== count($attendeeIds)) {
            throw new LiveChatRefundSelectionException(__('Choose at least one ticket of this order.'));
        }

        if ($selected->count() > $coverage->maxTickets) {
            throw new LiveChatRefundSelectionException(__('Your protection covers :count ticket(s) at most.', [
                'count' => $coverage->maxTickets,
            ]));
        }

        return $selected;
    }

    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     */
    public function total(Collection $tickets): float
    {
        return $this->fromCents((int) $tickets->sum(fn (LiveChatRefundTicketDTO $ticket) => $this->toCents($ticket->amount)));
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}
