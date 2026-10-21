<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsAssistantTrigger;
use Illuminate\Support\Collection;

class SmsAssistantRequestDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, SmsAssistantThreadMessageDTO>  $thread
     * @param  Collection<int, SmsCustomerOrderDTO>  $customerOrders
     * @param  Collection<int, SmsAssistantEventInfoDTO>  $events
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly ?int $conversationId,
        public readonly SmsAssistantTrigger $trigger,
        public readonly string $organizerName,
        public readonly string $timezone,
        public readonly ?string $instructions,
        public readonly ?string $contactName,
        public readonly Collection $thread,
        public readonly Collection $customerOrders,
        public readonly Collection $events,
    ) {}
}
