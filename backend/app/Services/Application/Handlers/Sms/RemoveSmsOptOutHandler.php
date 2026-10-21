<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Sms\SmsOptOutService;

class RemoveSmsOptOutHandler
{
    public function __construct(
        private readonly SmsOptOutService $optOutService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $optOutId): void
    {
        $this->optOutService->remove($organizerId, $optOutId);
    }
}
