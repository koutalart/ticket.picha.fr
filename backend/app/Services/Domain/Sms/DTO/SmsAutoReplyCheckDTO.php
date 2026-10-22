<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\SmsConversationDomainObject;

class SmsAutoReplyCheckDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?SmsAutoReplySkipReason $skipReason,
        public readonly ?SmsConversationDomainObject $conversation = null,
        public readonly ?SmsAutoReplyType $type = null,
        public readonly ?string $content = null,
    ) {}

    public function isAllowed(): bool
    {
        return $this->skipReason === null && $this->conversation !== null && $this->type !== null && $this->content !== null;
    }
}
