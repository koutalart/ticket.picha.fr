<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateBoxOfficeOperatorDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $event_id,
        public readonly int $account_id,
        public readonly int $created_by_user_id,
        public readonly string $first_name,
        public readonly string $last_name,
        public readonly string $email,
    ) {}
}
