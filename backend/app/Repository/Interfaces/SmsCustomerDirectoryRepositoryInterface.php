<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\Services\Domain\Sms\DTO\SmsCustomerContactDTO;
use Illuminate\Support\Collection;

interface SmsCustomerDirectoryRepositoryInterface
{
    /**
     * @return Collection<int, SmsCustomerContactDTO> most recent orders first
     */
    public function findCustomersWithPhone(int $organizerId): Collection;
}
