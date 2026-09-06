<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;

class CreateBoxOfficeSaleDTO extends BaseDataObject
{
    public function __construct(
        public readonly int                    $event_id,
        public readonly int                    $agent_user_id,
        public readonly int                    $product_id,
        public readonly int                    $product_price_id,
        public readonly string                 $phone,
        public readonly string                 $first_name,
        public readonly string                 $last_name,
        public readonly string                 $email,
        public readonly string                 $locale,
        public readonly float                  $amount,
        public readonly BoxOfficePaymentMethod  $payment_method,
        public readonly float                  $amount_collected,
        public readonly string                 $idempotency_key,
        /** @var CreateBoxOfficeSaleItemDTO[] */
        public readonly array                  $items = [],
    )
    {
    }
}
