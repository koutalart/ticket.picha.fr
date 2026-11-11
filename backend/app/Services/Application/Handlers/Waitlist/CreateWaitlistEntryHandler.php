<?php

namespace HiEvents\Services\Application\Handlers\Waitlist;

use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Waitlist\DTO\CreateWaitlistEntryDTO;
use HiEvents\Services\Domain\Sms\SmsAvailabilityService;
use HiEvents\Services\Domain\Waitlist\CreateWaitlistEntryService;
use Illuminate\Validation\ValidationException;

class CreateWaitlistEntryHandler
{
    public function __construct(
        private readonly CreateWaitlistEntryService       $createWaitlistEntryService,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ProductPriceRepositoryInterface  $productPriceRepository,
        private readonly ProductRepositoryInterface       $productRepository,
        private readonly EventRepositoryInterface         $eventRepository,
        private readonly SmsAvailabilityService           $smsAvailabilityService,
    )
    {
    }

    /**
     * @throws ResourceConflictException
     * @throws ResourceNotFoundException
     */
    public function handle(CreateWaitlistEntryDTO $dto): WaitlistEntryDomainObject
    {
        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $dto->event_id,
        ]);

        $productPrice = $this->productPriceRepository->findById($dto->product_price_id);

        $product = $this->productRepository->findFirstWhere([
            'id' => $productPrice->getProductId(),
            'event_id' => $dto->event_id,
        ]);

        if ($product === null) {
            throw new ResourceNotFoundException(__('Product not found for this event'));
        }

        $this->validatePhone($dto);

        return $this->createWaitlistEntryService->createEntry($dto, $eventSettings, $product);
    }

    /**
     * @throws ValidationException
     */
    private function validatePhone(CreateWaitlistEntryDTO $dto): void
    {
        if ($dto->phone !== null && trim($dto->phone) !== '') {
            if (SmsPhoneNormalizer::normalize($dto->phone) === null) {
                throw ValidationException::withMessages(['phone' => __('This phone number is not valid.')]);
            }

            return;
        }

        $event = $this->eventRepository->findFirst($dto->event_id);

        if ($event?->getOrganizerId() !== null && $this->smsAvailabilityService->canSend($event->getOrganizerId())) {
            throw ValidationException::withMessages(['phone' => __('Your phone number is required to be told by SMS when a place frees up.')]);
        }
    }
}
