<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Exceptions\NoTicketsAvailableException;
use HiEvents\Jobs\Attendee\SendTicketAfterAttendeeContactChangeJob;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\DTO\EditAttendeeDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\AttendeeEvent;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditAttendeeHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductQuantityUpdateService $productQuantityService,
        private readonly DatabaseManager $databaseManager,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(EditAttendeeDTO $editAttendeeDTO): AttendeeDomainObject
    {
        return $this->databaseManager->transaction(function () use ($editAttendeeDTO) {
            $attendee = $this->getAttendee($editAttendeeDTO);

            $this->validateProductId($editAttendeeDTO, $attendee);

            $this->adjustProductQuantities($attendee, $editAttendeeDTO);

            $updatedAttendee = $this->updateAttendee($editAttendeeDTO);

            $this->domainEventDispatcherService->dispatch(
                new AttendeeEvent(
                    type: DomainEventType::ATTENDEE_UPDATED,
                    attendeeId: $updatedAttendee->getId(),
                )
            );

            if ($this->contactChanged($attendee, $updatedAttendee) && $updatedAttendee->getStatus() === AttendeeStatus::ACTIVE->name) {
                SendTicketAfterAttendeeContactChangeJob::dispatch(
                    $updatedAttendee->getId(),
                    $updatedAttendee->getEventId(),
                    true,
                    $updatedAttendee->getPhone() !== null,
                )->afterCommit();
            }

            return $updatedAttendee;
        });
    }

    private function adjustProductQuantities(AttendeeDomainObject $attendee, EditAttendeeDTO $editAttendeeDTO): void
    {
        if ($attendee->getProductPriceId() !== $editAttendeeDTO->product_price_id) {
            $this->productQuantityService->decreaseQuantitySold($attendee->getProductPriceId());
            $this->productQuantityService->increaseQuantitySold($editAttendeeDTO->product_price_id);

            event(new CapacityChangedEvent(
                eventId: $editAttendeeDTO->event_id,
                direction: CapacityChangeDirection::INCREASED,
                productId: $attendee->getProductId(),
                productPriceId: $attendee->getProductPriceId(),
            ));
        }
    }

    private function contactChanged(AttendeeDomainObject $before, AttendeeDomainObject $after): bool
    {
        $normalize = fn (?string $value) => mb_strtolower(trim((string) $value));

        return $normalize($before->getFirstName()) !== $normalize($after->getFirstName())
            || $normalize($before->getLastName()) !== $normalize($after->getLastName())
            || $normalize($before->getEmail()) !== $normalize($after->getEmail())
            || $normalize($before->getPhone()) !== $normalize($after->getPhone());
    }

    private function updateAttendee(EditAttendeeDTO $editAttendeeDTO): AttendeeDomainObject
    {
        return $this->attendeeRepository->updateByIdWhere($editAttendeeDTO->attendee_id, [
            'first_name' => $editAttendeeDTO->first_name,
            'last_name' => $editAttendeeDTO->last_name,
            'email' => $editAttendeeDTO->email,
            'product_id' => $editAttendeeDTO->product_id,
            'product_price_id' => $editAttendeeDTO->product_price_id,
            'notes' => $editAttendeeDTO->notes,
            'phone' => $editAttendeeDTO->phone !== null && trim($editAttendeeDTO->phone) !== '' ? trim($editAttendeeDTO->phone) : null,
        ], [
            'event_id' => $editAttendeeDTO->event_id,
        ]);
    }

    /**
     * @throws ValidationException
     * @throws NoTicketsAvailableException
     */
    private function validateProductId(
        EditAttendeeDTO $editAttendeeDTO,
        AttendeeDomainObject $attendee,
    ): void {
        /** @var ProductDomainObject $product */
        $product = $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->findFirstWhere([
                ProductDomainObjectAbstract::ID => $editAttendeeDTO->product_id,
            ]);

        if ($product->getEventId() !== $editAttendeeDTO->event_id) {
            throw ValidationException::withMessages([
                'product_id' => __('Product ID is not valid'),
            ]);
        }

        $productPriceIds = $product->getProductPrices()->map(fn ($productPrice) => $productPrice->getId())->toArray();
        if (! in_array($editAttendeeDTO->product_price_id, $productPriceIds, true)) {
            throw ValidationException::withMessages([
                'product_price_id' => __('Product price ID is not valid'),
            ]);
        }

        // No need to check availability if the product price hasn't changed
        if ($attendee->getProductPriceId() === $editAttendeeDTO->product_price_id) {
            return;
        }

        $availableQuantity = $this->productRepository->getQuantityRemainingForProductPrice(
            productId: $editAttendeeDTO->product_id,
            productPriceId: $product->getType() === ProductPriceType::TIERED->name
                ? $editAttendeeDTO->product_price_id
                : $product->getProductPrices()->first()->getId(),
        );

        if ($availableQuantity <= 0) {
            throw new NoTicketsAvailableException(
                __('There are no products available. If you would like to assign this product to this attendee, please adjust the product\'s available quantity.')
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function getAttendee(EditAttendeeDTO $editAttendeeDTO): AttendeeDomainObject
    {
        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObjectAbstract::EVENT_ID => $editAttendeeDTO->event_id,
            AttendeeDomainObjectAbstract::ID => $editAttendeeDTO->attendee_id,
        ]);

        if ($attendee === null) {
            throw ValidationException::withMessages([
                'attendee_id' => __('Attendee ID is not valid'),
            ]);
        }

        return $attendee;
    }
}
