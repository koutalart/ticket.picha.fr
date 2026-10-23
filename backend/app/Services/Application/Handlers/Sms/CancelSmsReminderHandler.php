<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsCampaignDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderNotCancellableException;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;

class CancelSmsReminderHandler
{
    public function __construct(
        private readonly SmsReminderSchedulingService $schedulingService,
        private readonly GetSmsRemindersHandler $remindersHandler,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderNotCancellableException
     */
    public function handle(int $organizerId, int $campaignId): SmsCampaignDomainObject
    {
        $this->schedulingService->cancel($organizerId, $campaignId);

        $reminder = $this->remindersHandler->handle($organizerId, $campaignId)->first();
        if ($reminder === null) {
            throw new ResourceNotFoundException(__('Reminder not found'));
        }

        return $reminder;
    }
}
