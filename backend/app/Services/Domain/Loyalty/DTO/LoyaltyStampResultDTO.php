<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Loyalty\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\LoyaltyCardDomainObject;

class LoyaltyStampResultDTO extends BaseDataObject
{
    public function __construct(
        public LoyaltyCardDomainObject $card,
        public bool $reward_unlocked,
    ) {}
}
