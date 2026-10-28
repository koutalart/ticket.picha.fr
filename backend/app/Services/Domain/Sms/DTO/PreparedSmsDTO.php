<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;

/**
 * An SMS checked for sending (valid number, no STOP, configured line) whose message is not recorded yet.
 */
class PreparedSmsDTO extends BaseDataObject
{
    public function __construct(
        public readonly SendSmsDTO $sms,
        public readonly string $phone,
        public readonly OrganizerSmsSettingDomainObject $line,
        public readonly SmsConversationDomainObject $conversation,
    ) {}
}
