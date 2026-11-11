<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\ChangeProductPriceHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\ChangeProductPriceDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. Example: php artisan anicka:changer-prix --organisateur=4 --evenement=7 --tarif=12 --prix=20
 * The price is the ticket price without the service fees, which the buyer always pays on top.
 */
class ChangePriceAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:changer-prix
        {--evenement= : Event id (required)}
        {--tarif= : Product id (required)}
        {--prix= : New price without the service fees, e.g. 20 or 12.50 (required)}
        {--niveau-prix= : Price id, required for a ticket with several prices}';

    protected $description = 'Assistant, level 2: change the price of a ticket or product';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->requiredId('tarif');
        $this->optionalId('niveau-prix');
        $this->amount('prix');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        [$product, $price] = $this->target($scope);

        if ($product->getIsRefundProtection()) {
            throw new AssistantActionRefusedException(__('The cancellation protection cannot be changed here.'));
        }

        return __('Change the price of ":product" from :old to :new (service fees paid by the buyer on top).', [
            'product' => trim($product->getTitle().' '.($price->getLabel() ?? '')),
            'old' => $this->money($price->getPrice(), $scope->event->getCurrency()),
            'new' => $this->money($this->amount('prix'), $scope->event->getCurrency()),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        [$product, $price] = $this->target($scope);

        $updated = $this->laravel->make(ChangeProductPriceHandler::class)->handle(new ChangeProductPriceDTO(
            eventId: $scope->eventId(),
            productId: $product->getId(),
            productPriceId: $price->getId(),
            price: $this->amount('prix'),
        ));

        return [
            'tarif_id' => $product->getId(),
            'niveau_prix_id' => $updated->getId(),
            'ancien_prix' => $price->getPrice(),
            'nouveau_prix' => $updated->getPrice(),
            'devise' => $scope->event->getCurrency(),
        ];
    }

    /**
     * @return array{0: ProductDomainObject, 1: ProductPriceDomainObject}
     *
     * @throws AssistantActionRefusedException
     */
    private function target(AssistantScopeDTO $scope): array
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->laravel->make(ProductRepositoryInterface::class)
            ->loadRelation(ProductPriceDomainObject::class)
            ->findFirstWhere(['id' => $this->requiredId('tarif'), 'event_id' => $scope->eventId()]);

        if ($product === null) {
            throw new AssistantActionRefusedException(__('Product :id not found for this event.', ['id' => $this->requiredId('tarif')]));
        }

        $prices = $product->getProductPrices();
        $priceId = $this->optionalId('niveau-prix');

        if ($priceId === null && $prices->count() !== 1) {
            throw new AssistantActionRefusedException(__('This ticket has several prices: give --niveau-prix (:ids).', [
                'ids' => $prices->map(fn (ProductPriceDomainObject $p) => $p->getId().' '.($p->getLabel() ?? ''))->implode(', '),
            ]));
        }

        $price = $priceId === null
            ? $prices->first()
            : $prices->first(fn (ProductPriceDomainObject $p) => $p->getId() === $priceId);

        if ($price === null) {
            throw new AssistantActionRefusedException(__('Price :id not found for this product.', ['id' => $priceId]));
        }

        return [$product, $price];
    }
}
