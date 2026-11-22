<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands\Assistant;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\Exceptions\AssistantActionRefusedException;
use HiEvents\Exceptions\PosterMediaException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\ApplyEventPosterHandler;
use HiEvents\Services\Application\Handlers\Event\CreateDraftEventHandler;
use HiEvents\Services\Application\Handlers\Event\CreateEventImageHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventImageDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\CreateDraftEventDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftQuestionDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent\DraftTicketDTO;
use HiEvents\Services\Domain\Assistant\DTO\AssistantScopeDTO;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Level 1: the event is created as a draft, nobody sees it until it is published with anicka:statut-evenement (level 2).
 * The cover must first be copied by the server into storage/app/anicka-fichiers (billetterie.py does it from /data/fichiers).
 *
 * Example: php artisan anicka:creer-evenement --organisateur=4 --titre="Soirée Gala" --debut="2026-12-24 20:00"
 *   --fin="2026-12-25 04:00" --lieu="Pôle culturel, Chirongui"
 *   --tarifs='[{"nom":"Prévente","prix":15,"quantite":200},{"nom":"Invitation","prix":0,"quantite":50}]'
 *   --questions='[{"libelle":"Ville","type":"texte","obligatoire":true},{"libelle":"Repas","type":"choix","options":["Viande","Poisson"]}]'
 */
class CreateEventAssistantCommand extends AbstractAssistantCommand
{
    public const TIMEZONE = 'Indian/Mayotte';

    public const FILES_DIRECTORY = 'app/anicka-fichiers';

    private const COVER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_TICKETS = 20;

    private const MAX_QUESTIONS = 30;

    private const QUESTION_TYPES = [
        'texte' => QuestionTypeEnum::SINGLE_LINE_TEXT,
        'nombre' => QuestionTypeEnum::SINGLE_LINE_TEXT,
        'telephone' => QuestionTypeEnum::PHONE,
        'téléphone' => QuestionTypeEnum::PHONE,
        'choix' => QuestionTypeEnum::RADIO,
    ];

    protected $signature = 'anicka:creer-evenement
        {--titre= : Event title (required)}
        {--description= : Event description}
        {--debut= : Start, "Y-m-d H:i", Mayotte time (required)}
        {--fin= : End, "Y-m-d H:i", Mayotte time}
        {--lieu= : Venue}
        {--adresse= : Address of the venue}
        {--couverture= : Cover image, file name inside storage/app/anicka-fichiers}
        {--tarifs= : JSON list of tickets: [{"nom":"Prévente","prix":15,"quantite":200}], prix without fees, 0 = free (required)}
        {--questions= : JSON list of order form questions: [{"libelle":"Ville","type":"texte|nombre|telephone|choix","obligatoire":true,"options":["A","B"]}]}
        {--affiche= : Poster (file path or https URL): accent colour, flyer and short banner}';

    protected $description = 'Assistant, level 1: create a draft event (never published) with its tickets and order form';

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
        $this->title();
        $start = $this->date('debut', required: true);
        $end = $this->date('fin', required: false);

        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw new AssistantActionRefusedException(__('The end must be after the start.'));
        }

        $this->tickets();
        $this->questions();
        $this->coverPath();

        $poster = $this->optionalString('affiche');

        if ($poster !== null && ! str_starts_with($poster, 'https://')) {
            $this->localPosterPath($poster);
        }
    }

    protected function describe(AssistantScopeDTO $scope): string
    {
        $start = $this->date('debut', required: true);

        $existing = $this->laravel->make(EventRepositoryInterface::class)->findFirstWhere([
            'organizer_id' => $scope->organizer->getId(),
            'title' => $this->title(),
            'start_date' => $start->copy()->utc()->format('Y-m-d H:i:s'),
        ]);

        if ($existing !== null) {
            throw new AssistantActionRefusedException(__('This event already exists (id :id).', ['id' => $existing->getId()]));
        }

        $tickets = array_map(
            fn (DraftTicketDTO $ticket) => $ticket->title.' '.($ticket->isFree()
                ? __('free')
                : $this->money($ticket->price, 'EUR')).($ticket->quantity !== null ? ' x'.$ticket->quantity : ''),
            $this->tickets(),
        );

        return __('Create the draft event ":title" for :organizer on :date, tickets: :tickets, :questions question(s). Not published.', [
            'title' => $this->title(),
            'organizer' => $scope->organizer->getName(),
            'date' => $start->format('d/m/Y H:i'),
            'tickets' => implode(', ', $tickets),
            'questions' => count($this->questions()),
        ]);
    }

    protected function perform(AssistantScopeDTO $scope): array
    {
        $end = $this->date('fin', required: false);

        $result = $this->laravel->make(CreateDraftEventHandler::class)->handle(new CreateDraftEventDTO(
            organizer_id: $scope->organizer->getId(),
            account_id: $scope->accountId(),
            user_id: $this->accountOwnerUserId($scope),
            title: $this->title(),
            start_date: $this->date('debut', required: true)->format('Y-m-d H:i:s'),
            timezone: self::TIMEZONE,
            tickets: $this->tickets(),
            questions: $this->questions(),
            end_date: $end?->format('Y-m-d H:i:s'),
            description: $this->optionalString('description'),
            venue_name: $this->optionalString('lieu'),
            address: $this->optionalString('adresse'),
        ));

        $eventId = $result->event->getId();
        $coverPath = $this->coverPath();

        if ($coverPath !== null) {
            $this->laravel->make(CreateEventImageHandler::class)->handle(new CreateEventImageDTO(
                eventId: $eventId,
                accountId: $scope->accountId(),
                image: new UploadedFile($coverPath, basename($coverPath), mime_content_type($coverPath) ?: null, null, true),
                imageType: ImageType::EVENT_COVER,
            ));
        }

        return [
            'evenement_id' => $eventId,
            'statut' => $result->event->getStatus(),
            'tarifs' => $result->ticket_ids,
            'questions' => $result->question_ids,
            'couverture' => $coverPath !== null,
            'page' => $this->applyPage($scope, $eventId),
            'gerer' => rtrim((string) config('app.frontend_url'), '/').'/manage/event/'.$eventId,
        ];
    }

    /**
     * The event exists whatever happens here: a poster problem is reported, never fatal.
     */
    private function applyPage(AssistantScopeDTO $scope, int $eventId): array
    {
        try {
            $applied = $this->laravel->make(ApplyEventPosterHandler::class)->handle(
                eventId: $eventId,
                organizerId: $scope->organizer->getId(),
                accountId: $scope->accountId(),
                userId: $this->accountOwnerUserId($scope),
                posterPath: $this->poster(),
            );
        } catch (PosterMediaException|CouldNotUploadImageException|ResourceNotFoundException|AssistantActionRefusedException $e) {
            return ['modele' => false, 'erreur' => $e->getMessage()];
        }

        return ['modele' => true, 'couleur' => $applied->accent];
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function poster(): ?string
    {
        $value = $this->optionalString('affiche');

        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, 'https://')) {
            $response = Http::timeout(30)->get($value);

            if (! $response->successful()) {
                throw new AssistantActionRefusedException(__('The poster could not be downloaded.'));
            }

            $path = tempnam(sys_get_temp_dir(), 'affiche');
            file_put_contents($path, $response->body());

            return $path;
        }

        return $this->localPosterPath($value);
    }

    /**
     * A file name alone points inside storage/app/anicka-fichiers, where billetterie.py copies the client files.
     *
     * @throws AssistantActionRefusedException
     */
    private function localPosterPath(string $value): string
    {
        $path = str_starts_with($value, '/') ? $value : storage_path(self::FILES_DIRECTORY.'/'.basename($value));

        if (! is_file($path) || ! is_readable($path)) {
            throw new AssistantActionRefusedException(__('The poster file :path cannot be read.', ['path' => $value]));
        }

        return $path;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function title(): string
    {
        $title = $this->requiredString('titre');

        if (mb_strlen($title) > 150) {
            throw new AssistantActionRefusedException(__('The title is too long (150 characters at most).'));
        }

        return $title;
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function date(string $option, bool $required): ?Carbon
    {
        $value = $this->localDateTime($option);

        if ($value === null) {
            if ($required) {
                throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => $option]));
            }

            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $value, self::TIMEZONE)->startOfMinute();
    }

    /**
     * @return DraftTicketDTO[]
     *
     * @throws AssistantActionRefusedException
     */
    private function tickets(): array
    {
        $items = $this->jsonList('tarifs', required: true);

        if (count($items) > self::MAX_TICKETS) {
            throw new AssistantActionRefusedException(__('At most :max tickets.', ['max' => self::MAX_TICKETS]));
        }

        return array_map(function (mixed $item, int $index) {
            $position = $index + 1;
            $name = is_array($item) && is_string($item['nom'] ?? null) ? trim($item['nom']) : '';

            if ($name === '' || mb_strlen($name) > 100) {
                throw new AssistantActionRefusedException(__('Ticket :position: "nom" is required (100 characters at most).', ['position' => $position]));
            }

            $price = $item['prix'] ?? null;

            if (is_string($price)) {
                $price = str_replace([' ', ','], ['', '.'], $price);
            }

            if (! is_numeric($price) || (float) $price < 0) {
                throw new AssistantActionRefusedException(__('Ticket :position: "prix" must be an amount without fees, 0 for a free ticket.', ['position' => $position]));
            }

            $quantity = $item['quantite'] ?? null;

            if ($quantity !== null && (! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity)) || (int) $quantity <= 0)) {
                throw new AssistantActionRefusedException(__('Ticket :position: "quantite" must be a positive whole number.', ['position' => $position]));
            }

            return new DraftTicketDTO(
                title: $name,
                price: round((float) $price, 2),
                quantity: $quantity !== null ? (int) $quantity : null,
            );
        }, $items, array_keys($items));
    }

    /**
     * @return DraftQuestionDTO[]
     *
     * @throws AssistantActionRefusedException
     */
    private function questions(): array
    {
        $items = $this->jsonList('questions', required: false);

        if (count($items) > self::MAX_QUESTIONS) {
            throw new AssistantActionRefusedException(__('At most :max questions.', ['max' => self::MAX_QUESTIONS]));
        }

        return array_map(function (mixed $item, int $index) {
            $position = $index + 1;
            $label = is_array($item) && is_string($item['libelle'] ?? null) ? trim($item['libelle']) : '';

            if ($label === '' || mb_strlen($label) > 255) {
                throw new AssistantActionRefusedException(__('Question :position: "libelle" is required (255 characters at most).', ['position' => $position]));
            }

            $typeName = mb_strtolower(trim((string) ($item['type'] ?? 'texte')));

            if (! isset(self::QUESTION_TYPES[$typeName])) {
                throw new AssistantActionRefusedException(__('Question :position: "type" must be one of: :allowed.', [
                    'position' => $position,
                    'allowed' => 'texte, nombre, telephone, choix',
                ]));
            }

            $type = self::QUESTION_TYPES[$typeName];
            $options = null;

            if ($type === QuestionTypeEnum::RADIO) {
                $options = array_values(array_unique(array_filter(
                    array_map(fn (mixed $option) => is_scalar($option) ? trim((string) $option) : '', (array) ($item['options'] ?? [])),
                    fn (string $option) => $option !== '',
                )));

                if (count($options) < 2) {
                    throw new AssistantActionRefusedException(__('Question :position: a "choix" question needs at least 2 options.', ['position' => $position]));
                }
            }

            return new DraftQuestionDTO(
                title: $label,
                type: $type,
                required: $this->isYes($item['obligatoire'] ?? false),
                options: $options,
                description: $typeName === 'nombre' ? __('Answer in figures.') : null,
            );
        }, $items, array_keys($items));
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

    private function isYes(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'oui', 'yes'], true);
    }

    /**
     * @throws AssistantActionRefusedException
     */
    private function jsonList(string $option, bool $required): array
    {
        $value = $this->optionalString($option);

        if ($value === null) {
            if ($required) {
                throw new AssistantActionRefusedException(__('The option --:option is required.', ['option' => $option]));
            }

            return [];
        }

        try {
            $items = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $items = null;
        }

        if (! is_array($items) || ! array_is_list($items) || ($required && $items === [])) {
            throw new AssistantActionRefusedException(__('The option --:option must be a JSON list.', ['option' => $option]));
        }

        return $items;
    }
}
