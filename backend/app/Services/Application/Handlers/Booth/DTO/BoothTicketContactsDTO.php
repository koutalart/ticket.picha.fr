<?php

namespace HiEvents\Services\Application\Handlers\Booth\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoothTicketContactsDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}
}
