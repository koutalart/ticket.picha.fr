<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\OrganizerAssistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantThreadMessageDTO;
use Illuminate\Support\Collection;

class OrganizerAssistantRequestDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, SmsAssistantThreadMessageDTO>  $thread
     * @param  Collection<int, SmsAssistantEventInfoDTO>  $events
     * @param  array<int, array<string, mixed>>  $registrations  Registration figures per upcoming event
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly ?int $conversationId,
        public readonly string $organizerName,
        public readonly string $timezone,
        public readonly ?string $instructions,
        public readonly ?string $contactName,
        public readonly Collection $thread,
        public readonly Collection $events,
        public readonly array $registrations = [],
    ) {}
}
