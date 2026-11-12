<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Services\Application\Handlers\Account\CreateManagedOrganizationHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateManagedOrganizationDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;

/**
 * Level 2. Example: php artisan anicka:creer-organisation --nom="CCI Mayotte" --email=contact@cci.yt --prenom=Catherine --nom-contact=Mkadara
 */
class CreateOrganizationAssistantCommand extends AbstractAssistantCommand
{
    public const TIMEZONE = 'Indian/Mayotte';

    protected $signature = 'anicka:creer-organisation
        {--nom= : Name of the organizer shown to buyers (required)}
        {--email= : E-mail of the client, owner of the new account (required)}
        {--telephone= : Phone of the organizer}
        {--prenom= : First name of the contact person (required)}
        {--nom-contact= : Last name of the contact person}';

    protected $description = 'Assistant, level 2: create a client (account, user and organizer), nothing is sent to the client';

    protected function level(): int
    {
        return 2;
    }

    protected function requiresOrganizer(): bool
    {
        return false;
    }

    protected function requiresEvent(): bool
    {
        return false;
    }

    protected function validateInput(): void
    {
        if (mb_strlen($this->requiredString('nom')) > 100) {
            throw new AssistantActionRefusedException(__('The name must have at most 100 characters.'));
        }

        if (filter_var($this->requiredString('email'), FILTER_VALIDATE_EMAIL) === false) {
            throw new AssistantActionRefusedException(__('The e-mail address is not valid.'));
        }

        $this->requiredString('prenom');
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        try {
            $this->laravel->make(CreateManagedOrganizationHandler::class)->ensureEmailIsFree($this->requiredString('email'));
        } catch (EmailAlreadyExists) {
            throw new AssistantActionRefusedException(__('The e-mail :email already owns an account.', ['email' => $this->requiredString('email')]));
        }

        return __('Create the client ":name" (account owned by :person, :email), platform fee paid by the buyer, nothing sent to the client.', [
            'name' => $this->requiredString('nom'),
            'person' => trim($this->requiredString('prenom').' '.($this->optionalString('nom-contact') ?? '')),
            'email' => strtolower($this->requiredString('email')),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $created = $this->laravel->make(CreateManagedOrganizationHandler::class)->handle(new CreateManagedOrganizationDTO(
            organizer_name: $this->requiredString('nom'),
            email: $this->requiredString('email'),
            first_name: $this->requiredString('prenom'),
            last_name: $this->optionalString('nom-contact'),
            phone: $this->optionalString('telephone'),
            timezone: self::TIMEZONE,
            currency: 'EUR',
            locale: 'fr',
        ));

        return [
            'organisateur' => $created->organizer->getId(),
            'compte' => $created->account->getId(),
            'utilisateur' => $created->user->getId(),
        ];
    }
}
