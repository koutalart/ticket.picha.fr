<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Http\Request\QrLink\UpsertQrLinkRequest;
use HiEvents\Services\Application\Handlers\QrLink\DTO\UpsertQrLinkDTO;

class QrLinkDTOFactory
{
    public static function fromRequest(UpsertQrLinkRequest $request, int $accountId, int $organizerId): UpsertQrLinkDTO
    {
        $eventId = $request->validated('event_id');

        return new UpsertQrLinkDTO(
            accountId: $accountId,
            organizerId: $organizerId,
            name: $request->validated('name'),
            destinationType: QrLinkDestinationType::from($request->validated('destination_type')),
            status: QrLinkStatus::from($request->validated('status', QrLinkStatus::ACTIVE->value)),
            eventId: $eventId !== null ? (int) $eventId : null,
            destinationUrl: $request->validated('destination_url'),
            title: $request->validated('title'),
            description: $request->validated('description'),
            socialLinks: $request->validated('social_links'),
            design: $request->validated('design'),
        );
    }
}
