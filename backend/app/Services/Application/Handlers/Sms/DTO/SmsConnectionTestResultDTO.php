<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsConnectionTestResultDTO extends BaseDataObject
{
    /**
     * @param  string[]  $phoneNumbers
     */
    public function __construct(
        public readonly array $phone_numbers,
        public readonly bool $from_number_registered,
    ) {}
}
