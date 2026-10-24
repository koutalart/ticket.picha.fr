<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsBulkSendResultDTO extends BaseDataObject
{
    /**
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $optedOut
     * @param  array<int, string>  $failed
     */
    public function __construct(
        public readonly array $sent = [],
        public readonly array $optedOut = [],
        public readonly array $failed = [],
    ) {}
}
