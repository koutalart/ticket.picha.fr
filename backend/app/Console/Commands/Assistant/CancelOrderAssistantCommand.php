<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\Order\CancelOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CancelOrderDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. Example: php artisan anicka:annuler-commande --organisateur=4 --evenement=7 --commande=1520
 */
class CancelOrderAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:annuler-commande
        {--evenement= : Event id (required)}
        {--commande= : Order id (required)}
        {--rembourser : Also refund the ticket price (never the service fees)}';

    protected $description = 'Assistant, level 2: cancel an order and its tickets';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->requiredId('commande');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $order = $this->order($scope);

        if ($order->isOrderCancelled()) {
            throw new AssistantActionRefusedException(__('This order is already cancelled.'));
        }

        return __('Cancel order :order of :name (:paid)', [
            'order' => $order->getShortId(),
            'name' => trim($order->getFirstName().' '.$order->getLastName()),
            'paid' => $this->money($order->getTotalGross(), $order->getCurrency()),
        ]).($this->option('rembourser') ? __(', and refund the ticket price.') : __(', without refund.'));
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $order = $this->laravel->make(CancelOrderHandler::class)->handle(new CancelOrderDTO(
            eventId: $scope->eventId(),
            orderId: $this->requiredId('commande'),
            refund: (bool) $this->option('rembourser'),
        ));

        return [
            'commande_id' => $order->getId(),
            'statut' => $order->getStatus(),
            'remboursement' => (bool) $this->option('rembourser'),
        ];
    }
}
