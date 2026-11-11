<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 1. Example: php artisan anicka:ajouter-invite --organisateur=4 --evenement=7 --tarif=12 --prenom=Awa --nom=Said --email=awa@example.com
 */
class AddFreeGuestAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:ajouter-invite
        {--evenement= : Event id (required)}
        {--tarif= : Ticket (product) id (required)}
        {--niveau-prix= : Price id, only for a ticket with several prices}
        {--prenom= : First name (required)}
        {--nom= : Last name (required)}
        {--email= : E-mail address (required)}
        {--sans-email : Do not send the ticket by e-mail}';

    protected $description = 'Assistant, level 1: add a free guest to an event (ticket at 0, taken from the stock)';

    protected function level(): int
    {
        return 1;
    }

    protected function validateInput(): void
    {
        $this->requiredId('tarif');
        $this->optionalId('niveau-prix');
        $this->requiredString('prenom');
        $this->requiredString('nom');

        if (filter_var($this->requiredString('email'), FILTER_VALIDATE_EMAIL) === false) {
            throw new AssistantActionRefusedException(__('The e-mail address is not valid.'));
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $product = $this->product($scope);

        return __('Add :name (:email) as a free guest, ticket ":ticket".', [
            'name' => $this->requiredString('prenom').' '.$this->requiredString('nom'),
            'email' => $this->requiredString('email'),
            'ticket' => $product->getTitle(),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $attendee = $this->laravel->make(CreateAttendeeHandler::class)->handle(new CreateAttendeeDTO(
            first_name: $this->requiredString('prenom'),
            last_name: $this->requiredString('nom'),
            email: $this->requiredString('email'),
            product_id: $this->requiredId('tarif'),
            event_id: $scope->eventId(),
            send_confirmation_email: ! $this->option('sans-email'),
            locale: 'fr',
            product_price_id: $this->optionalId('niveau-prix'),
            is_free: true,
        ));

        return [
            'participant_id' => $attendee->getId(),
            'commande_id' => $attendee->getOrderId(),
            'billet_envoye' => ! $this->option('sans-email'),
        ];
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function product(AssistantScopeDTO $scope): ProductDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->laravel->make(ProductRepositoryInterface::class)->findFirstWhere([
            'id' => $this->requiredId('tarif'),
            'event_id' => $scope->eventId(),
            'product_type' => ProductType::TICKET->name,
        ]);

        if ($product === null) {
            throw new AssistantActionRefusedException(__('Ticket :id not found for this event.', ['id' => $this->requiredId('tarif')]));
        }

        if ($product->getIsRefundProtection()) {
            throw new AssistantActionRefusedException(__('The cancellation protection cannot be changed here.'));
        }

        return $product;
    }
}
