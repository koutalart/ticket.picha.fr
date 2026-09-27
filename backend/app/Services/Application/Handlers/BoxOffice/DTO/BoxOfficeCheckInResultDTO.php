<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeCheckInResultDTO extends BaseDataObject
{
    public const CHECKED_IN = 'CHECKED_IN';

    public const ALREADY_CHECKED_IN = 'ALREADY_CHECKED_IN';

    public const REFUSED = 'REFUSED';

    public function __construct(
        public readonly string $attendee_public_id,
        public readonly string $status,
        public readonly ?string $checked_in_at = null,
        public readonly ?string $message = null,
    ) {}
}
