<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeContextEventDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $currency,
        public readonly ?string $timezone,
        public readonly ?string $country,
        public readonly ?string $calling_code,
        public readonly ?string $last_printer_host = null,
    ) {}
}
