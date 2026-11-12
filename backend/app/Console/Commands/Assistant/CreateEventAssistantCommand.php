<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use Carbon\Carbon;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\CreateEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventImageDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use Illuminate\Http\UploadedFile;

/**
 * Level 2. Creates a draft event, dates in Mayotte time. The cover must first be copied by the server into
 * storage/app/anicka-fichiers (billetterie.py does it from /data/fichiers/<client>/).
 * Example: php artisan anicka:creer-evenement --organisateur=18 --titre="Foire de l'Industrie" --debut="2026-11-21 08:00" --fin="2026-11-21 18:00" --lieu="Pôle culturel" --adresse="Chirongui"
 */
class CreateEventAssistantCommand extends AbstractAssistantCommand
{
    public const FILES_DIRECTORY = 'app/anicka-fichiers';

    private const COVER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $signature = 'anicka:creer-evenement
        {--titre= : Title of the event (required)}
        {--debut= : Start, "Y-m-d H:i" in Mayotte time (required)}
        {--fin= : End, "Y-m-d H:i" in Mayotte time}
        {--lieu= : Name of the venue}
        {--adresse= : Address of the venue}
        {--description= : Description, plain text or simple HTML}
        {--couverture= : Cover image, path inside storage/app/anicka-fichiers}';

    protected $description = 'Assistant, level 2: create a draft event for an organizer';

    protected function level(): int
    {
        return 2;
    }

    protected function requiresEvent(): bool
    {
        return false;
    }

    protected function validateInput(): void
    {
        if (mb_strlen($this->requiredString('titre')) > 150) {
            throw new AssistantActionRefusedException(__('The title must have at most 150 characters.'));
        }

        $this->requiredString('debut');
        $start = $this->localDateTime('debut');
        $end = $this->localDateTime('fin');

        if ($end !== null && Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
            throw new AssistantActionRefusedException(__('The end must be after the start.'));
        }

        $this->coverPath();
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        return __('Create the draft event ":title" for :organizer on :date (Mayotte time):cover.', [
            'title' => $this->requiredString('titre'),
            'organizer' => $scope->organizer->getName(),
            'date' => Carbon::parse($this->localDateTime('debut'))->format('d/m/Y H:i'),
            'cover' => $this->coverPath() !== null ? __(', with its cover') : '',
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $location = $this->optionalString('lieu') !== null || $this->optionalString('adresse') !== null
            ? new AddressDTO(venue_name: $this->optionalString('lieu'), address_line_1: $this->optionalString('adresse'))
            : null;

        $event = $this->laravel->make(CreateEventHandler::class)->handle(new CreateEventDTO(
            title: $this->requiredString('titre'),
            organizer_id: $scope->organizer->getId(),
            account_id: $scope->accountId(),
            user_id: $this->accountOwnerUserId($scope),
            start_date: $this->localDateTime('debut'),
            end_date: $this->localDateTime('fin'),
            description: $this->description(),
            timezone: CreateOrganizationAssistantCommand::TIMEZONE,
            currency: $scope->organizer->getCurrency() ?? 'EUR',
            location_details: $location,
            status: EventStatus::DRAFT->name,
        ));

        $this->laravel->make(EventSettingsRepositoryInterface::class)->updateWhere(
            attributes: [
                'pass_platform_fee_to_buyer' => true,
                'location_details' => $location?->toArray(),
                'is_online_event' => false,
            ],
            where: ['event_id' => $event->getId()],
        );

        $coverPath = $this->coverPath();

        if ($coverPath !== null) {
            $this->laravel->make(CreateEventImageHandler::class)->handle(new CreateEventImageDTO(
                eventId: $event->getId(),
                accountId: $scope->accountId(),
                image: new UploadedFile($coverPath, basename($coverPath), mime_content_type($coverPath) ?: null, null, true),
                imageType: ImageType::EVENT_COVER,
            ));
        }

        return [
            'evenement' => $event->getId(),
            'statut' => EventStatus::DRAFT->name,
            'apercu' => sprintf(Url::getFrontEndUrlFromConfig(Url::EVENT_HOMEPAGE), $event->getId(), $event->getSlug()),
            'couverture' => $coverPath !== null,
        ];
    }

    private function description(): ?string
    {
        $description = $this->optionalString('description');

        if ($description === null || $description !== strip_tags($description)) {
            return $description;
        }

        $paragraphs = preg_split('/\R{2,}/', str_replace('\n', "\n", $description)) ?: [];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph)), false).'</p>',
            array_filter($paragraphs, fn (string $paragraph) => trim($paragraph) !== ''),
        ));
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function coverPath(): ?string
    {
        $value = $this->optionalString('couverture');

        if ($value === null) {
            return null;
        }

        $directory = realpath(storage_path(self::FILES_DIRECTORY));
        $path = realpath(str_starts_with($value, '/') ? $value : storage_path(self::FILES_DIRECTORY.'/'.$value));

        if ($directory === false || $path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new AssistantActionRefusedException(__('The cover :file was not found in the files given to the assistant.', ['file' => $value]));
        }

        if (! in_array(mime_content_type($path), self::COVER_MIME_TYPES, true)) {
            throw new AssistantActionRefusedException(__('The cover must be a JPEG, PNG or WebP image.'));
        }

        return $path;
    }
}
