<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\EmailProvider\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class InboundEmailDTO extends BaseDataObject
{
    /**
     * @param  array<int, string>  $recipients  every address the e-mail was sent to (To, Cc, delivered-for)
     * @param  array<int, string>  $references  Message-IDs of the In-Reply-To and References headers
     * @param  array<int, array{filename: ?string, content_type: ?string, size: ?int}>  $attachments
     */
    public function __construct(
        public readonly string $providerEmailId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $recipients,
        public readonly ?string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly ?string $messageId,
        public readonly array $references,
        public readonly array $attachments,
        public readonly ?string $receivedAt,
    ) {}
}
