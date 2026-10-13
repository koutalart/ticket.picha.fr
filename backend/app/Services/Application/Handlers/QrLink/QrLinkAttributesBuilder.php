<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;

class QrLinkAttributesBuilder
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function build(UpsertQrLinkDTO $dto): array
    {
        $this->assertEventBelongsToOrganizer($dto->eventId, $dto->organizerId);

        $isUrl = $dto->destinationType === QrLinkDestinationType::URL;

        return [
            'name' => $this->plainText($dto->name),
            'destination_type' => $dto->destinationType->value,
            'destination_url' => $isUrl ? $dto->destinationUrl : null,
            'event_id' => $dto->eventId,
            'title' => $this->plainText($dto->title),
            'description' => $this->plainText($dto->description),
            'social_links' => $this->socialLinks($dto->socialLinks),
            'design' => $this->design($dto->design),
            'status' => $dto->status->value,
        ];
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function assertEventBelongsToOrganizer(?int $eventId, int $organizerId): void
    {
        if ($eventId === null) {
            return;
        }

        $event = $this->eventRepository->findFirstWhere([
            EventDomainObjectAbstract::ID => $eventId,
            EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if (! $event instanceof EventDomainObject) {
            throw new ResourceNotFoundException(__('Event not found'));
        }
    }

    private function socialLinks(?array $links): ?array
    {
        if (! $links) {
            return null;
        }

        return array_values(array_map(static fn (array $link) => [
            'platform' => $link['platform'],
            'url' => $link['url'],
        ], $links));
    }

    private function design(?array $design): ?array
    {
        if (! $design) {
            return null;
        }

        return array_filter([
            'foreground' => $design['foreground'] ?? null,
            'background' => $design['background'] ?? null,
            'show_logo' => isset($design['show_logo']) ? (bool) $design['show_logo'] : null,
        ], static fn ($value) => $value !== null);
    }

    private function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(strip_tags($value));

        return $value === '' ? null : $value;
    }
}
