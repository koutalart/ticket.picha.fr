<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeAttendeeSearchResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $public_id,
        public readonly ?string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $email,
        public readonly ?string $product_title,
        public readonly string $status,
        public readonly ?string $checked_in_at,
    ) {}
}
