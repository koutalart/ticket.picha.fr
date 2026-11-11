<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DTO\SetProductSalesPausedDTO;
use HiEvents\Services\Application\Handlers\Product\SetProductSalesPausedHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 1. Example: php artisan anicka:vente-tarif --organisateur=4 --evenement=7 --tarif=12 --etat=fermee
 */
class ProductSaleAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:vente-tarif
        {--evenement= : Event id (required)}
        {--tarif= : Product id (required)}
        {--etat= : ouverte (sale open) or fermee (sale paused) (required)}';

    protected $description = 'Assistant, level 1: open or close the sale of a ticket or product';

    protected function level(): int
    {
        return 1;
    }

    protected function validateInput(): void
    {
        $this->requiredId('tarif');
        $this->choiceOption('etat', ['ouverte', 'fermee']);
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->laravel->make(ProductRepositoryInterface::class)->findFirstWhere([
            'id' => $this->requiredId('tarif'),
            'event_id' => $scope->eventId(),
        ]);

        if ($product === null) {
            throw new AssistantActionRefusedException(__('Product :id not found for this event.', ['id' => $this->requiredId('tarif')]));
        }

        return $this->paused()
            ? __('Close the sale of ":product".', ['product' => $product->getTitle()])
            : __('Open the sale of ":product".', ['product' => $product->getTitle()]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $product = $this->laravel->make(SetProductSalesPausedHandler::class)->handle(new SetProductSalesPausedDTO(
            eventId: $scope->eventId(),
            productId: $this->requiredId('tarif'),
            salesPaused: $this->paused(),
        ));

        return [
            'tarif_id' => $product->getId(),
            'tarif' => $product->getTitle(),
            'vente' => $product->getIsSalesPaused() ? 'fermee' : 'ouverte',
        ];
    }

    private function paused(): bool
    {
        return $this->choiceOption('etat', ['ouverte', 'fermee']) === 'fermee';
    }
}
