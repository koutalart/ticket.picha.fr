<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsAssistantEventInfoDTO extends BaseDataObject
{
    /**
     * @param  SmsAssistantTicketInfoDTO[]  $tickets
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $venue,
        public readonly string $url,
        public readonly array $tickets,
    ) {}
}
