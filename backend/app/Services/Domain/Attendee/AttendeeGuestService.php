<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\Constants;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Exceptions\AttendeeGuestNotAllowedException;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeAttendanceContextDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestAvailabilityDTO;
use HiEvents\Services\Domain\Attendee\DTO\AttendeeGuestDataDTO;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsReactivationService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

class AttendeeGuestService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface            $attendeeRepository,
        private readonly ProductRepositoryInterface             $productRepository,
        private readonly ProductPriceRepositoryInterface        $productPriceRepository,
        private readonly OrderItemRepositoryInterface           $orderItemRepository,
        private readonly QuestionRepositoryInterface            $questionRepository,
        private readonly QuestionAnswerRepositoryInterface      $questionAnswerRepository,
        private readonly AvailableProductQuantitiesFetchService $availableQuantitiesService,
        private readonly ProductQuantityUpdateService           $productQuantityUpdateService,
        private readonly EventStatisticsReactivationService     $eventStatisticsReactivationService,
        private readonly AttendeePublicIdGenerator              $publicIdGenerator,
        private readonly Dispatcher                             $eventDispatcher,
    )
    {
    }

    public function getAvailability(AttendeeAttendanceContextDTO $context): AttendeeGuestAvailabilityDTO
    {
        return $this->buildAvailability($context, $this->findProduct($context));
    }

    /**
     * Must run inside a database transaction: the product price row stays locked until commit.
     *
     * @throws AttendeeGuestNotAllowedException
     * @throws Throwable
     */
    public function addGuest(AttendeeAttendanceContextDTO $context, AttendeeGuestDataDTO $guest): AttendeeDomainObject
    {
        $attendee = $context->attendee;

        $this->productPriceRepository->lockForUpdateById($attendee->getProductPriceId());

        $availability = $this->buildAvailability($context, $this->findProduct($context));

        if (!$availability->canAddGuest) {
            throw new AttendeeGuestNotAllowedException($availability->reason);
        }

        $order = $context->order;

        /** @var AttendeeDomainObject $newAttendee */
        $newAttendee = $this->attendeeRepository->create([
            AttendeeDomainObjectAbstract::EVENT_ID => $attendee->getEventId(),
            AttendeeDomainObjectAbstract::PRODUCT_ID => $attendee->getProductId(),
            AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $attendee->getProductPriceId(),
            AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
            AttendeeDomainObjectAbstract::EMAIL => $order->getEmail(),
            AttendeeDomainObjectAbstract::FIRST_NAME => $guest->firstName,
            AttendeeDomainObjectAbstract::LAST_NAME => $guest->lastName,
            AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
            AttendeeDomainObjectAbstract::PUBLIC_ID => $this->publicIdGenerator->generateUnique(),
            AttendeeDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            AttendeeDomainObjectAbstract::LOCALE => $order->getLocale(),
        ]);

        $orderItem = $this->orderItemRepository->findFirstWhere([
            'order_id' => $order->getId(),
            'product_price_id' => $attendee->getProductPriceId(),
        ]);

        if ($orderItem !== null) {
            $this->orderItemRepository->increment($orderItem->getId(), 'quantity');
        }

        $this->productQuantityUpdateService->increaseQuantitySold($attendee->getProductPriceId());

        $this->eventStatisticsReactivationService->incrementForReactivatedAttendee(
            eventId: $attendee->getEventId(),
            orderDate: $order->getCreatedAt(),
            attendeeCount: 1,
            productsSoldCount: $orderItem !== null ? 1 : 0,
        );

        $this->storePhone($newAttendee, $guest->phone, $context->locale);

        $this->eventDispatcher->dispatch(new CapacityChangedEvent(
            eventId: $attendee->getEventId(),
            direction: CapacityChangeDirection::DECREASED,
            productId: $attendee->getProductId(),
            productPriceId: $attendee->getProductPriceId(),
        ));

        return $newAttendee;
    }

    private function findProduct(AttendeeAttendanceContextDTO $context): ?ProductDomainObject
    {
        return $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findFirstWhere([
                'id' => $context->attendee->getProductId(),
                'event_id' => $context->attendee->getEventId(),
            ]);
    }

    private function buildAvailability(AttendeeAttendanceContextDTO $context, ?ProductDomainObject $product): AttendeeGuestAvailabilityDTO
    {
        $locale = $context->locale;
        $attendee = $context->attendee;

        if ($attendee->getStatus() !== AttendeeStatus::ACTIVE->name
            || $context->order?->getStatus() !== OrderStatus::COMPLETED->name) {
            return $this->refuse(__('This registration cannot be changed.', [], $locale));
        }

        if ($context->event->isEventInPast()) {
            return $this->refuse(__('The event is over, guests can no longer be added.', [], $locale));
        }

        if ($product === null || !$this->isFreeTicket($product)) {
            return $this->refuse(__('Guests can only be added to free registrations.', [], $locale));
        }

        $maxPerOrder = $product->getMaxPerOrder();
        $activeAttendees = $this->attendeeRepository->countWhere([
            'order_id' => $attendee->getOrderId(),
            'product_id' => $attendee->getProductId(),
            'status' => AttendeeStatus::ACTIVE->name,
        ]);

        if ($maxPerOrder === null || $activeAttendees >= $maxPerOrder) {
            return $this->refuse(__('This registration already has the maximum number of places.', [], $locale));
        }

        $stock = $this->getRemainingStock($attendee);

        if ($stock <= 0) {
            return $this->refuse(__('There are no places left for this ticket.', [], $locale));
        }

        return new AttendeeGuestAvailabilityDTO(
            canAddGuest: true,
            placesRemaining: min($maxPerOrder - $activeAttendees, $stock),
            reason: null,
        );
    }

    private function isFreeTicket(ProductDomainObject $product): bool
    {
        if ($product->getProductType() !== ProductType::TICKET->name
            || $product->getType() === ProductPriceType::DONATION->name) {
            return false;
        }

        $prices = $product->getProductPrices();

        return $prices !== null
            && $prices->isNotEmpty()
            && $prices->every(fn(ProductPriceDomainObject $price) => (float)$price->getPrice() === 0.0);
    }

    private function getRemainingStock(AttendeeDomainObject $attendee): int
    {
        /** @var AvailableProductQuantitiesDTO|null $quantity */
        $quantity = $this->availableQuantitiesService
            ->getAvailableProductQuantities($attendee->getEventId(), ignoreCache: true)
            ->productQuantities
            ->first(fn(AvailableProductQuantitiesDTO $dto) => $dto->price_id === $attendee->getProductPriceId());

        if ($quantity === null) {
            return 0;
        }

        return min($quantity->quantity_available, Constants::INFINITE);
    }

    private function storePhone(AttendeeDomainObject $guest, ?string $phone, ?string $locale): void
    {
        if ($phone === null || $phone === '') {
            return;
        }

        /** @var QuestionDomainObject|null $question */
        $question = $this->questionRepository
            ->loadRelation(ProductDomainObject::class)
            ->findWhere([
                'event_id' => $guest->getEventId(),
                'type' => QuestionTypeEnum::PHONE->name,
                'belongs_to' => QuestionBelongsTo::PRODUCT->name,
            ])
            ->first(fn(QuestionDomainObject $question) => $question->getProducts()
                    ?->contains(fn(ProductDomainObject $product) => $product->getId() === $guest->getProductId())
                ?? false);

        if ($question !== null) {
            $this->questionAnswerRepository->create([
                'question_id' => $question->getId(),
                'answer' => $phone,
                'order_id' => $guest->getOrderId(),
                'product_id' => $guest->getProductId(),
                'attendee_id' => $guest->getId(),
            ]);

            return;
        }

        $this->attendeeRepository->updateWhere(
            attributes: [AttendeeDomainObjectAbstract::NOTES => __('Phone: :phone', ['phone' => $phone], $locale)],
            where: ['id' => $guest->getId()],
        );
    }

    private function refuse(string $reason): AttendeeGuestAvailabilityDTO
    {
        return new AttendeeGuestAvailabilityDTO(
            canAddGuest: false,
            placesRemaining: 0,
            reason: $reason,
        );
    }
}
