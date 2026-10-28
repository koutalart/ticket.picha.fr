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
     * @param  list<string>  $pendingQuestions  questions already sent to the organizer and not answered yet
     * @param  array<int, array<string, array<int, array<string, string>>>>  $knowledge  per event id: confirmed facts, changes, announcements
     * @param  array<int, array<string, string>>  $publications  latest publications of the organizer
     * @param  string|null  $questionContactNote  what to say about the contact details of a question kept for them
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
        public readonly ?string $greeting = null,
        public readonly bool $handedOff = false,
        public readonly int $visitorMessageNumber = 0,
        public readonly ?string $contactPolicy = null,
        public readonly array $pendingQuestions = [],
        public readonly array $knowledge = [],
        public readonly array $publications = [],
        public readonly ?string $questionContactNote = null,
    ) {}
}
