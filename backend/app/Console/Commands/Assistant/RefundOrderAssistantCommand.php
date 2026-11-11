<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Domain\Order\OrderOrganizerShareService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;

/**
 * Level 2. Example: php artisan anicka:rembourser --organisateur=4 --evenement=7 --commande=1520 --montant=15
 * Only the ticket price is refunded, never the service fees nor the cancellation protection (RefundOrderHandler).
 */
class RefundOrderAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:rembourser
        {--evenement= : Event id (required)}
        {--commande= : Order id (required)}
        {--montant= : Amount refunded to the buyer, at most the price of the tickets (required)}
        {--annuler : Also cancel the order and its tickets}
        {--sans-email : Do not tell the buyer by e-mail}';

    protected $description = 'Assistant, level 2: refund an order paid by card (ticket price only)';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $this->requiredId('commande');

        if ($this->amount('montant') <= 0) {
            throw new AssistantActionRefusedException(__('The amount must be greater than zero.'));
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $order = $this->order($scope);
        $refundable = $this->laravel->make(OrderOrganizerShareService::class)->getShare($order)->refundableToBuyer;

        if ($this->amount('montant') > $refundable) {
            throw new AssistantActionRefusedException(__('At most :amount can be refunded on this order (ticket price only, never the service fees).', [
                'amount' => $this->money($refundable, $order->getCurrency()),
            ]));
        }

        return __('Refund :amount to :name (order :order, paid :paid)', [
            'amount' => $this->money($this->amount('montant'), $order->getCurrency()),
            'name' => trim($order->getFirstName().' '.$order->getLastName()),
            'order' => $order->getShortId(),
            'paid' => $this->money($order->getTotalGross(), $order->getCurrency()),
        ]).($this->option('annuler') ? __(', and cancel the order.') : '.');
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $order = $this->laravel->make(RefundOrderHandler::class)->handle(new RefundOrderDTO(
            event_id: $scope->eventId(),
            order_id: $this->requiredId('commande'),
            amount: $this->amount('montant'),
            notify_buyer: ! $this->option('sans-email'),
            cancel_order: (bool) $this->option('annuler'),
            refund_source: StripePaymentIntentRefundService::REFUND_SOURCE_ASSISTANT,
        ));

        return [
            'commande_id' => $order->getId(),
            'montant' => $this->amount('montant'),
            'devise' => $order->getCurrency(),
            'statut_remboursement' => $order->getRefundStatus(),
            'commande_annulee' => (bool) $this->option('annuler'),
        ];
    }
}
