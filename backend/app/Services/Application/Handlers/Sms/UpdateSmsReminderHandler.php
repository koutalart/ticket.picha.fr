<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotEditableException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;
use Throwable;

class UpdateSmsReminderHandler
{
    public function __construct(
        private readonly SmsReminderSchedulingService $schedulingService,
        private readonly GetSmsRemindersHandler $remindersHandler,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderValidationException
     * @throws SmsReminderNotEditableException
     * @throws Throwable
     */
    public function handle(int $reminderId, SmsReminderRequestDTO $dto): SmsCampaignDomainObject
    {
        $reminder = $this->schedulingService->update($reminderId, $dto);

        return $this->remindersHandler->handle($dto->organizerId, $reminderId)->first() ?? $reminder;
    }
}
