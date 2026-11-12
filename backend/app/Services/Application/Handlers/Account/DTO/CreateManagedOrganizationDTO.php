<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Account\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateManagedOrganizationDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $organizer_name,
        public readonly string $email,
        public readonly string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $phone,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly string $locale,
    ) {}
}
