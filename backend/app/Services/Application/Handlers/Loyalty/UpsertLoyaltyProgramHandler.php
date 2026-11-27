<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty;

use HiEvents\DomainObjects\LoyaltyProgramDomainObject;
use HiEvents\Jobs\Loyalty\SyncLoyaltyProgramPassesJob;
use HiEvents\Repository\Interfaces\LoyaltyProgramRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\LoyaltyLocationDTO;
use HiEvents\Services\Application\Handlers\Loyalty\DTO\UpsertLoyaltyProgramDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use HiEvents\Services\Infrastructure\Wallet\LoyaltyWalletPassService;

class UpsertLoyaltyProgramHandler
{
    public function __construct(
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly LoyaltyWalletPassService $walletPassService,
        private readonly HtmlPurifierService $purifier,
    ) {}

    public function handle(UpsertLoyaltyProgramDTO $dto): LoyaltyProgramDomainObject
    {
        $attributes = [
            'name' => $this->clean($dto->name, 80),
            'reward_description' => $this->clean($dto->reward_description, 120),
            'stamps_required' => $dto->stamps_required,
            'background_color' => strtoupper($dto->background_color),
            'foreground_color' => strtoupper($dto->foreground_color),
            'label_color' => strtoupper($dto->label_color),
            'logo_url' => $dto->logo_url,
            'locations' => array_map(fn (LoyaltyLocationDTO $location) => [
                'latitude' => round($location->latitude, 7),
                'longitude' => round($location->longitude, 7),
                'relevant_text' => $location->relevant_text === null ? null : $this->clean($location->relevant_text, 120),
            ], array_slice($dto->locations, 0, 10)),
            'max_distance' => $dto->max_distance,
            'beacon_uuid' => $dto->beacon_uuid === null ? null : strtolower($dto->beacon_uuid),
            'beacon_major' => $dto->beacon_uuid === null ? null : $dto->beacon_major,
            'beacon_minor' => $dto->beacon_uuid === null ? null : $dto->beacon_minor,
            'beacon_relevant_text' => $dto->beacon_uuid === null || $dto->beacon_relevant_text === null
                ? null
                : $this->clean($dto->beacon_relevant_text, 120),
            'is_active' => $dto->is_active,
        ];

        $existing = $this->programRepository->findFirstWhere(['organizer_id' => $dto->organizer_id]);

        $program = $existing === null
            ? $this->programRepository->create(array_merge($attributes, [
                'organizer_id' => $dto->organizer_id,
                'account_id' => $dto->account_id,
            ]))
            : $this->programRepository->updateFromArray($existing->getId(), $attributes);

        $organizer = $this->organizerRepository->findById($dto->organizer_id);
        $this->walletPassService->syncGoogleClass($program, $organizer->getName());

        if ($existing !== null) {
            SyncLoyaltyProgramPassesJob::dispatch($program->getId());
        }

        return $program;
    }

    private function clean(string $value, int $max): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $this->purifier->purify($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, $max);
    }
}
