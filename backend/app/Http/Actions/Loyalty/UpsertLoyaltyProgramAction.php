<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Loyalty;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Loyalty\UpsertLoyaltyProgramRequest;
use HiEvents\Resources\Loyalty\LoyaltyProgramResource;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\LoyaltyLocationDTO;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\UpsertLoyaltyProgramDTO;
use HiEvents\Services\Application\Handlers\Loyalty\UpsertLoyaltyProgramHandler;
use Illuminate\Http\JsonResponse;

class UpsertLoyaltyProgramAction extends BaseAction
{
    public function __construct(
        private readonly UpsertLoyaltyProgramHandler $handler,
    ) {}

    public function __invoke(UpsertLoyaltyProgramRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $beaconUuid = $request->validated('beacon_uuid');

        $program = $this->handler->handle(new UpsertLoyaltyProgramDTO(
            account_id: $this->getAuthenticatedAccountId(),
            organizer_id: $organizerId,
            name: (string) $request->validated('name'),
            reward_description: (string) $request->validated('reward_description'),
            stamps_required: (int) $request->validated('stamps_required'),
            background_color: (string) $request->validated('background_color'),
            foreground_color: (string) $request->validated('foreground_color'),
            label_color: (string) $request->validated('label_color'),
            logo_url: $request->validated('logo_url'),
            locations: array_map(static fn (array $location) => new LoyaltyLocationDTO(
                latitude: (float) $location['latitude'],
                longitude: (float) $location['longitude'],
                relevant_text: $location['relevant_text'] ?? null,
            ), $request->validated('locations') ?? []),
            max_distance: $request->validated('max_distance') === null ? null : (int) $request->validated('max_distance'),
            beacon_uuid: $beaconUuid === null || $beaconUuid === '' ? null : (string) $beaconUuid,
            beacon_major: $request->validated('beacon_major') === null ? null : (int) $request->validated('beacon_major'),
            beacon_minor: $request->validated('beacon_minor') === null ? null : (int) $request->validated('beacon_minor'),
            beacon_relevant_text: $request->validated('beacon_relevant_text'),
            is_active: (bool) $request->validated('is_active'),
        ));

        return $this->resourceResponse(LoyaltyProgramResource::class, $program);
    }
}
