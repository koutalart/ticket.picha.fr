<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Enums\QrPosterTemplate;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ImageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\QrCampaignDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\QrLinkReferenceException;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\QrCampaignRepositoryInterface;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;

class QrLinkAttributesBuilder
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly QrCampaignRepositoryInterface $qrCampaignRepository,
    ) {}

    /**
     * @throws QrLinkReferenceException
     */
    public function build(UpsertQrLinkDTO $dto): array
    {
        $this->assertEventBelongsToOrganizer($dto->eventId, $dto->organizerId);
        $this->assertCampaignBelongsToOrganizer($dto->qrCampaignId, $dto->organizerId);

        $isUrl = $dto->destinationType === QrLinkDestinationType::URL;

        return [
            'name' => $this->plainText($dto->name),
            'destination_type' => $dto->destinationType->value,
            'destination_url' => $isUrl ? $dto->destinationUrl : null,
            'event_id' => $dto->eventId,
            'qr_campaign_id' => $dto->qrCampaignId,
            'title' => $this->plainText($dto->title),
            'description' => $this->plainText($dto->description),
            'social_links' => $this->socialLinks($dto->socialLinks),
            'design' => $this->design($dto->design, $dto->accountId),
            'status' => $dto->status->value,
        ];
    }

    /**
     * @throws QrLinkReferenceException
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
            throw new QrLinkReferenceException('event_id', __('Event not found'));
        }
    }

    /**
     * @throws QrLinkReferenceException
     */
    private function assertCampaignBelongsToOrganizer(?int $campaignId, int $organizerId): void
    {
        if ($campaignId === null) {
            return;
        }

        $campaign = $this->qrCampaignRepository->findFirstWhere([
            QrCampaignDomainObjectAbstract::ID => $campaignId,
            QrCampaignDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($campaign === null) {
            throw new QrLinkReferenceException('qr_campaign_id', __('Campaign not found'));
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

    /**
     * @throws QrLinkReferenceException
     */
    private function design(?array $design, int $accountId): ?array
    {
        if (! $design) {
            return null;
        }

        $template = QrPosterTemplate::tryFrom($design['template'] ?? '') ?? QrPosterTemplate::NONE;
        $background = $template === QrPosterTemplate::CUSTOM
            ? $this->backgroundImage((int) ($design['background_image_id'] ?? 0), $accountId)
            : null;

        return array_filter([
            'foreground' => $design['foreground'] ?? null,
            'background' => $design['background'] ?? null,
            'template' => $template->value,
            'background_image_id' => $background?->getId(),
            'background_image_url' => $background ? Url::getCdnUrl($background->getPath()) : null,
            'background_width' => $background?->getWidth(),
            'background_height' => $background?->getHeight(),
            'qr_position' => isset($design['qr_position']) ? (int) $design['qr_position'] : null,
            'qr_scale' => isset($design['qr_scale']) ? (int) $design['qr_scale'] : null,
            'caption' => $this->plainText($design['caption'] ?? null),
            'caption_color' => $design['caption_color'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @throws QrLinkReferenceException
     */
    private function backgroundImage(int $imageId, int $accountId): ImageDomainObject
    {
        $image = $this->imageRepository->findFirstWhere([
            ImageDomainObjectAbstract::ID => $imageId,
            ImageDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        if (! $image instanceof ImageDomainObject) {
            throw new QrLinkReferenceException('design.background_image_id', __('Background image not found'));
        }

        return $image;
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
