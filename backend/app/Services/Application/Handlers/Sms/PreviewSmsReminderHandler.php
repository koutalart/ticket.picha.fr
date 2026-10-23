<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReminderValidationException;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderPreviewDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderRequestDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;

class PreviewSmsReminderHandler
{
    public function __construct(
        private readonly SmsReminderSchedulingService $schedulingService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReminderValidationException
     */
    public function handle(SmsReminderRequestDTO $dto): SmsReminderPreviewDTO
    {
        return $this->schedulingService->preview($dto);
    }
}
