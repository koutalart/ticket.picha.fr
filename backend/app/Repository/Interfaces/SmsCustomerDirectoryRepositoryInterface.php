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

    /**
     * @return array<string, string> phone => contact name given to the organizer SMS conversation
     */
    public function findConversationNames(int $organizerId): array;
}
