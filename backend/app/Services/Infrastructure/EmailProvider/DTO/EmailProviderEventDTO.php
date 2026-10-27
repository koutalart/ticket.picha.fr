<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\EmailMessageStatus;

class EmailProviderEventDTO extends BaseDataObject
{
    public const KIND_STATUS = 'STATUS';

    public const KIND_INBOUND = 'INBOUND';

    public function __construct(
        public readonly string $kind,
        public readonly string $providerEmailId,
        public readonly ?EmailMessageStatus $status = null,
        public readonly ?string $occurredAt = null,
        public readonly ?string $errorMessage = null,
        /** @var array<int, string> addresses a received e-mail was sent to, as announced by the webhook */
        public readonly array $recipients = [],
    ) {}
}
