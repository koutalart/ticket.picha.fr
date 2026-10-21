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
        public readonly bool|Optional $notifyOnInboundSms = new Optional,
    ) {}
}
