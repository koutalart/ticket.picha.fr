<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Services\Domain\Sms\SmsOptOutService;

class AddSmsOptOutHandler
{
    public function __construct(
        private readonly SmsOptOutService $optOutService,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    public function handle(int $organizerId, string $phone): SmsOptOutDomainObject
    {
        return $this->optOutService->addManual($organizerId, $phone);
    }
}
