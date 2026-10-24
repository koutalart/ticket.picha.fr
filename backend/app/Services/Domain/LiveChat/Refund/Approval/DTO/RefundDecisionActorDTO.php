<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\RefundDecisionChannel;

class RefundDecisionActorDTO extends BaseDataObject
{
    public function __construct(
        public readonly RefundDecisionChannel $channel,
        public readonly ?string $phone = null,
        public readonly ?int $userId = null,
        public readonly ?string $name = null,
    ) {}
}
