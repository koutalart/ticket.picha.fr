<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\CreateCheckInListHandler;
use HiEvents\Services\Application\Handlers\CheckInList\DTO\UpsertCheckInListDTO;
use HiEvents\Services\Application\Handlers\QrLink\CreateQrLinkHandler;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;

/**
 * Level 2. One check-in list per name (a person or an entrance) covering all the tickets of the event, each with
 * its short link: whoever opens it scans the tickets, without an account.
 * Example: php artisan anicka:creer-acces-scan --organisateur=18 --evenement=25 --noms="Accueil 1|Accueil 2"
 */
class CreateCheckInAccessAssistantCommand extends AbstractAssistantCommand
{
    private const MAX_NAMES = 10;

    protected $signature = 'anicka:creer-acces-scan
        {--evenement= : Event id (required)}
        {--noms= : Names of the scan accesses, separated by | (required)}';

    protected $description = 'Assistant, level 2: create scan accesses (check-in lists) for the reception team';

    protected function level(): int
    {
        return 2;
    }

    protected function validateInput(): void
    {
        $names = $this->names();

        if ($names === []) {
            throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => 'noms']));
        }

        if (count($names) > self::MAX_NAMES) {
            throw new AssistantActionRefusedException(__('At most :count scan accesses at once.', ['count' => self::MAX_NAMES]));
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > 80) {
                throw new AssistantActionRefusedException(__('Each name must have at most 80 characters.'));
            }
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        if ($this->ticketIds($scope) === []) {
            throw new AssistantActionRefusedException(__('Create the tickets first: a scan access covers the tickets of the event.'));
        }

        return __('Create :count scan access(es) for ":event": :names.', [
            'count' => count($this->names()),
            'event' => $scope->event->getTitle(),
            'names' => implode(', ', $this->names()),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $ticketIds = $this->ticketIds($scope);
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');
        $accesses = [];

        foreach ($this->names() as $name) {
            $list = $this->laravel->make(CreateCheckInListHandler::class)->handle(new UpsertCheckInListDTO(
                name: $name,
                description: null,
                eventId: $scope->eventId(),
                productIds: $ticketIds,
            ));

            $qrLink = $this->laravel->make(CreateQrLinkHandler::class)->handle(new UpsertQrLinkDTO(
                accountId: $scope->accountId(),
                organizerId: $scope->organizer->getId(),
                name: __('Scan: :name', ['name' => $name]),
                destinationType: QrLinkDestinationType::URL,
                status: QrLinkStatus::ACTIVE,
                destinationUrl: $baseUrl.'/check-in/'.$list->getShortId(),
            ));

            $accesses[] = [
                'nom' => $name,
                'liste' => $list->getId(),
                'lien' => $this->laravel->make(QrLinkUrlService::class)->getPublicUrl($qrLink),
            ];
        }

        return ['acces' => $accesses];
    }

    /**
     * @return string[]
     */
    private function names(): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode('|', (string) $this->optionalString('noms'))),
            fn (string $name) => $name !== '',
        )));
    }

    /**
     * @return int[]
     */
    private function ticketIds(AssistantScopeDTO $scope): array
    {
        return $this->laravel->make(ProductRepositoryInterface::class)
            ->findWhere(['event_id' => $scope->eventId(), 'product_type' => ProductType::TICKET->name])
            ->map(fn (ProductDomainObject $product) => $product->getId())
            ->values()
            ->all();
    }
}
