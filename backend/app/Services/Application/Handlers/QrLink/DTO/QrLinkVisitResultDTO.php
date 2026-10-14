<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QrLinkVisitResultDTO extends BaseDataObject
{
    public function __construct(
        public string $destination_type,
        public ?string $redirect_url,
        public bool $recorded,
    ) {}
}
