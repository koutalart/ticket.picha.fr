<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Domain\Product\DTO\ProductPriceDTO;

/**
 * Level 2. A price of 0 makes a free ticket. The price never includes the platform fee (paid by the buyer), and
 * the cancellation protection is left as it is.
 * Example: php artisan anicka:creer-tarif --organisateur=18 --evenement=25 --nom="Entrée visiteur" --prix=0 --quantite=500
 */
class CreateTicketAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:creer-tarif
        {--evenement= : Event id (required)}
        {--nom= : Name of the ticket (required)}
        {--prix= : Price without fees, 0 for a free ticket (required)}
        {--quantite= : Number of places, unlimited when empty}';

    protected $description = 'Assistant, level 2: create a ticket for an event';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        if (mb_strlen($this->requiredString('nom')) > 100) {
            throw new AssistantActionRefusedException(__('The name must have at most 100 characters.'));
        }

        $this->amount('prix');
        $this->optionalId('quantite');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $price = $this->amount('prix');
        $quantity = $this->optionalId('quantite');

        return __('Create the ticket ":name" (:price) with :places for ":event".', [
            'name' => $this->requiredString('nom'),
            'price' => $price > 0
                ? __(':amount, platform fee paid by the buyer', ['amount' => $this->money($price, $scope->event->getCurrency())])
                : __('free'),
            'places' => $quantity === null ? __('unlimited places') : __(':count places', ['count' => $quantity]),
            'event' => $scope->event->getTitle(),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $price = $this->amount('prix');
        $type = $price > 0 ? ProductPriceType::PAID : ProductPriceType::FREE;

        $product = $this->laravel->make(CreateProductHandler::class)->handle(new UpsertProductDTO(
            account_id: $scope->accountId(),
            event_id: $scope->eventId(),
            product_category_id: $this->categoryId($scope),
            title: $this->requiredString('nom'),
            type: $type,
            product_type: ProductType::TICKET,
            prices: collect([new ProductPriceDTO(price: $price, initial_quantity_available: $this->optionalId('quantite'))]),
            order: $this->laravel->make(ProductRepositoryInterface::class)->countWhere(['event_id' => $scope->eventId()]) + 1,
            is_refund_protection: false,
        ));

        return [
            'tarif' => $product->getId(),
            'type' => $type->name,
            'prix' => $price,
            'quantite' => $this->optionalId('quantite'),
        ];
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function categoryId(AssistantScopeDTO $scope): int
    {
        /** @var ProductCategoryDomainObject|null $category */
        $category = $this->laravel->make(ProductCategoryRepositoryInterface::class)
            ->findWhere(['event_id' => $scope->eventId()])
            ->sortBy(fn (ProductCategoryDomainObject $category) => [(int) $category->getOrder(), $category->getId()])
            ->first();

        if ($category === null) {
            throw new AssistantActionRefusedException(__('This event has no ticket category.'));
        }

        return $category->getId();
    }
}
