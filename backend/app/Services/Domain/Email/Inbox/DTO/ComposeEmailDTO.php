<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ComposeEmailDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $userId,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly ?int $threadId = null,
        public readonly ?string $email = null,
    ) {}
}
