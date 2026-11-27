<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Loyalty\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpsertLoyaltyProgramDTO extends BaseDataObject
{
    /**
     * @param  LoyaltyLocationDTO[]  $locations
     */
    public function __construct(
        public int $account_id,
        public int $organizer_id,
        public string $name,
        public string $reward_description,
        public int $stamps_required,
        public string $background_color,
        public string $foreground_color,
        public string $label_color,
        public ?string $logo_url,
        public array $locations,
        public ?int $max_distance,
        public ?string $beacon_uuid,
        public ?int $beacon_major,
        public ?int $beacon_minor,
        public ?string $beacon_relevant_text,
        public bool $is_active,
    ) {}
}
