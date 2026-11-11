<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Services\Application\Handlers\QrLink\CreateQrLinkHandler;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Domain\QrLink\QrLinkUrlService;

/**
 * Level 1. Example: php artisan anicka:creer-lien --organisateur=4 --evenement=7 --nom="Affiche Mamoudzou"
 */
class CreateShortLinkAssistantCommand extends AbstractAssistantCommand
{
    protected $signature = 'anicka:creer-lien
        {--nom= : Name of the link, shown in the QR codes list (required)}
        {--evenement= : Event the link opens}
        {--url= : Or the web address the link opens (https://...)}';

    protected $description = 'Assistant, level 1: create a tracked short link with its QR code';

    protected function level(): int
    {
        return 1;
    }

    protected function requiresEvent(): bool
    {
        return false;
    }

    protected function validateInput(): void
    {
        $this->requiredString('nom');
        $url = $this->optionalString('url');

        if (($this->optionalId('evenement') === null) === ($url === null)) {
            throw new AssistantActionRefusedException(__('Give either --evenement or --url.'));
        }

        if ($url !== null && (filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $url))) {
            throw new AssistantActionRefusedException(__('The address must start with http:// or https://.'));
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        return __('Create the short link ":name" to :destination.', [
            'name' => $this->requiredString('nom'),
            'destination' => $scope->event?->getTitle() ?? $this->optionalString('url'),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $url = $this->optionalString('url');

        $qrLink = $this->laravel->make(CreateQrLinkHandler::class)->handle(new UpsertQrLinkDTO(
            accountId: $scope->accountId(),
            organizerId: $scope->organizer->getId(),
            name: $this->requiredString('nom'),
            destinationType: $url === null ? QrLinkDestinationType::EVENT : QrLinkDestinationType::URL,
            status: QrLinkStatus::ACTIVE,
            eventId: $scope->event?->getId(),
            destinationUrl: $url,
        ));

        return [
            'lien_id' => $qrLink->getId(),
            'lien' => $this->laravel->make(QrLinkUrlService::class)->getPublicUrl($qrLink),
            'qr_code' => __('QR code to download in the QR codes page of the dashboard.'),
        ];
    }
}
