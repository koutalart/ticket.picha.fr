<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsOptOutSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetSmsOptOutsHandler
{
    public function __construct(
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
    ) {}

    public function handle(int $organizerId, SmsOptOutSearchDTO $search): LengthAwarePaginator
    {
        return $this->optOutRepository->searchForOrganizer($organizerId, $search);
    }
}
