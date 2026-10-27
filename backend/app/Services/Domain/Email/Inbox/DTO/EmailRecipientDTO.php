<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EmailRecipientDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly ?int $threadId,
        public readonly bool $unsubscribed,
    ) {}
}
