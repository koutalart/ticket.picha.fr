<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;

class UpdateSmsReminderRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.SmsReminderRequest::MAX_LENGTH, 'not_regex:/^\s*$/'],
            'send_at' => ['required', 'date_format:'.SmsReminderSchedulingService::SEND_AT_FORMAT],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
