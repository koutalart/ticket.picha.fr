<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent;

use HiEvents\DataTransferObjects\BaseDataObject;

class DraftTicketDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $title,
        public readonly float $price,
        public readonly ?int $quantity = null,
    ) {}

    public function isFree(): bool
    {
        return $this->price <= 0;
    }
}
