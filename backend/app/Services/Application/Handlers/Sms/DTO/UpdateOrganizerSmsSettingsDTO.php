<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Spatie\LaravelData\Optional;

class UpdateOrganizerSmsSettingsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly string|Optional|null $apiKey,
        public readonly string|Optional|null $fromNumber,
        public readonly bool|Optional $assistantEnabled,
        public readonly string|Optional|null $assistantInstructions,
        public readonly bool|Optional $notifyOnInboundSms = new Optional,
        public readonly bool|Optional $assistantAutoSend = new Optional,
        public readonly string|Optional|null $assistantHoldingReply = new Optional,
        public readonly bool|Optional $defaultRemindersEnabled = new Optional,
    ) {}
}
