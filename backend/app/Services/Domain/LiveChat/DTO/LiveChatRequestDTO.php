<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use Illuminate\Support\Collection;

class LiveChatRequestDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $thread  oldest first, ends with the visitor message to answer
     * @param  Collection<int, SmsAssistantEventInfoDTO>  $events
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly int $sessionId,
        public readonly ?int $smsConversationId,
        public readonly string $organizerName,
        public readonly string $timezone,
        public readonly ?string $instructions,
        public readonly bool $tutoiement,
        public readonly bool $contactKnown,
        public readonly ?int $currentEventId,
        public readonly Collection $thread,
        public readonly Collection $events,
        public readonly bool $refundAvailable = false,
    ) {}
}
