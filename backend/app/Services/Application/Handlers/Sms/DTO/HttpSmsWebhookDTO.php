<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class HttpSmsWebhookDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $webhookToken,
        public readonly ?string $bearerToken,
        public readonly ?string $eventType,
        public readonly string $payload,
    ) {}
}
