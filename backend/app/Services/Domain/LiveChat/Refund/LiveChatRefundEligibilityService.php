<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\LiveChatRefundRefusal;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\LiveChatRefundNotEligibleException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\LiveChat\Refund\DTO\LiveChatRefundEligibilityDTO;
use Illuminate\Support\Collection;

/**
 * Decides, on the server only, whether an order can be refunded from the web chat. The model never decides.
 */
class LiveChatRefundEligibilityService
{
    public const MAX_REFERENCE_LENGTH = 40;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly LiveChatRefundAmountCalculator $calculator,
    ) {}

    /**
     * The order of an event of this organizer with this reference (public id or short id of the confirmation e-mail)
     * and this e-mail address, case-insensitive. Null in every other case, without telling which.
     */
    public function findOrder(int $organizerId, string $reference, string $email): ?OrderDomainObject
    {
        $reference = self::normalizeReference($reference);
        $email = mb_strtolower(trim($email));
        if ($reference === '' || $email === '') {
            return null;
        }

        $candidates = $this->orderRepository->findWhere([
            OrderDomainObjectAbstract::PUBLIC_ID => mb_strtoupper($reference),
        ])->merge($this->orderRepository->findWhere([
            OrderDomainObjectAbstract::SHORT_ID => $reference,
        ]));

        /** @var OrderDomainObject $order */
        foreach ($candidates as $order) {
            if (mb_strtolower(trim((string) $order->getEmail())) !== $email) {
                continue;
            }

            $event = $this->eventRepository->findFirstWhere([
                EventDomainObjectAbstract::ID => $order->getEventId(),
                EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]);

            if ($event !== null) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @throws LiveChatRefundNotEligibleException
     */
    public function orderOf(LiveChatRefundRequestDomainObject $request): OrderDomainObject
    {
        $order = $request->getOrderId() !== null
            ? $this->orderRepository->findFirstWhere([OrderDomainObjectAbstract::ID => $request->getOrderId()])
            : null;

        if ($order === null) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::ORDER_NOT_FOUND);
        }

        return $order;
    }

    public static function normalizeReference(string $reference): string
    {
        $reference = preg_replace('/\s+/u', '', trim($reference)) ?? '';

        return mb_substr(ltrim($reference, '#'), 0, self::MAX_REFERENCE_LENGTH);
    }

    /**
     * @throws LiveChatRefundNotEligibleException
     */
    public function check(OrderDomainObject $order, ?Carbon $now = null): LiveChatRefundEligibilityDTO
    {
        $now ??= Carbon::now('UTC');

        /** @var OrderDomainObject $order */
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findById($order->getId());

        if ($order->getStatus() !== OrderStatus::COMPLETED->name) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::NOT_COMPLETED);
        }

        if ($order->getPaymentProvider() !== PaymentProviders::STRIPE->name || $order->getStripePayment() === null) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::NOT_PAID_WITH_STRIPE);
        }

        if ($order->getRefundStatus() !== null) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::ALREADY_REFUNDED);
        }

        /** @var EventDomainObject $event */
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($order->getEventId());

        $items = $this->orderItemRepository->findWhere([OrderItemDomainObjectAbstract::ORDER_ID => $order->getId()]);
        $coverage = $this->calculator->coverage($items, $this->products($items), $this->attendees($order));

        if (! $coverage->hasProtection) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::NO_PROTECTION);
        }

        $this->checkTiming($event, $now);

        if ($coverage->tickets->isEmpty() || $coverage->maxTickets < 1) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::NO_REFUNDABLE_TICKET);
        }

        return new LiveChatRefundEligibilityDTO(order: $order, event: $event, coverage: $coverage);
    }

    /**
     * @throws LiveChatRefundNotEligibleException
     */
    private function checkTiming(EventDomainObject $event, Carbon $now): void
    {
        if ($event->getStartDate() === null) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::EVENT_STARTED);
        }

        $start = Carbon::parse($event->getStartDate(), 'UTC');
        if ($now->greaterThanOrEqualTo($start)) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::EVENT_STARTED);
        }

        $hours = (int) $event->getEventSettings()?->getRefundProtectionDeadlineHours();
        if ($hours > 0 && $now->copy()->addHours($hours)->greaterThan($start)) {
            throw new LiveChatRefundNotEligibleException(LiveChatRefundRefusal::DEADLINE_PASSED);
        }
    }

    /**
     * @param  Collection<int, OrderItemDomainObject>  $items
     * @return Collection<int, ProductDomainObject>
     */
    private function products(Collection $items): Collection
    {
        $ids = $items->map(fn (OrderItemDomainObject $item) => $item->getProductId())->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return $this->productRepository->findWhereIn('id', $ids)
            ->keyBy(fn (ProductDomainObject $product) => $product->getId());
    }

    /**
     * @return Collection<int, AttendeeDomainObject>
     */
    private function attendees(OrderDomainObject $order): Collection
    {
        return $this->attendeeRepository->findWhere([
            AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
        ]);
    }
}
