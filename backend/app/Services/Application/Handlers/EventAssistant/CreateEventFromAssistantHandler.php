<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\EventAssistant;

use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\EventAssistant\DTO\CreateEventFromAssistantDTO;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The event creation assistant creates the draft event and its single ticket in one go.
 */
class CreateEventFromAssistantHandler
{
    public function __construct(
        private readonly CreateEventHandler $createEventHandler,
        private readonly CreateProductHandler $createProductHandler,
        private readonly ProductCategoryRepositoryInterface $productCategoryRepository,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws OrganizerNotFoundException
     * @throws Throwable
     */
    public function handle(CreateEventFromAssistantDTO $dto): EventDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            $event = $this->createEventHandler->handle(new CreateEventDTO(
                title: $dto->title,
                organizer_id: $dto->organizer_id,
                account_id: $dto->account_id,
                user_id: $dto->user_id,
                start_date: $dto->start_date,
                end_date: $dto->end_date,
                description: $this->descriptionHtml($dto->description),
                category: EventCategory::tryFrom((string) $dto->category) ?? EventCategory::OTHER,
                location_details: $dto->venue ? new AddressDTO(venue_name: $dto->venue) : null,
            ));

            if ($dto->venue) {
                $this->eventSettingsRepository->updateWhere(
                    ['location_details' => (new AddressDTO(venue_name: $dto->venue))->toArray()],
                    ['event_id' => $event->getId()],
                );
            }

            $category = $this->productCategoryRepository->findFirstWhere(['event_id' => $event->getId()]);
            $isFree = $dto->price <= 0;

            $this->createProductHandler->handle(new UpsertProductDTO(
                account_id: $dto->account_id,
                event_id: $event->getId(),
                product_category_id: $category->getId(),
                title: $isFree ? __('Free entry') : __('Entry'),
                type: $isFree ? ProductPriceType::FREE : ProductPriceType::PAID,
                product_type: ProductType::TICKET,
                prices: collect([new ProductPriceDTO(
                    price: max(0.0, $dto->price),
                    initial_quantity_available: $dto->quantity,
                )]),
            ));

            return $event;
        });
    }

    /**
     * The assistant collects plain text: one paragraph per block separated by a blank line.
     */
    private function descriptionHtml(?string $description): ?string
    {
        $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $description) ?: []));
        if ($paragraphs === []) {
            return null;
        }

        return implode('', array_map(
            static fn (string $paragraph) => '<p>'.nl2br(e($paragraph), false).'</p>',
            $paragraphs,
        ));
    }
}
