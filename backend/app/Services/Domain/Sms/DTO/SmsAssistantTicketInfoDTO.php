<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsAssistantTicketInfoDTO extends BaseDataObject
{
    public const ON_SALE = 'on_sale';

    public const SOLD_OUT = 'sold_out';

    public const NOT_ON_SALE = 'not_on_sale_now';

    public function __construct(
        public readonly string $name,
        public readonly ?float $price,
        public readonly string $currency,
        public readonly string $availability,
    ) {}
}
