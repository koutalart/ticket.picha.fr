<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsAssistantEventInfoDTO extends BaseDataObject
{
    /**
     * @param  SmsAssistantTicketInfoDTO[]  $tickets
     * @param  array<int, array{titre: string, contenu: ?string}>  $practicalInfo
     * @param  array<int, array{question: string, reponse: string}>  $faq
     * @param  string[]  $artists
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $venue,
        public readonly string $url,
        public readonly array $tickets,
        public readonly ?int $id = null,
        public readonly ?string $description = null,
        public readonly ?bool $feesAddedAtCheckout = null,
        public readonly array $practicalInfo = [],
        public readonly array $faq = [],
        public readonly array $artists = [],
    ) {}
}
