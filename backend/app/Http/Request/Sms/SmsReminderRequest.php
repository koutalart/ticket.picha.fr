<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderSchedulingService;

class SmsReminderRequest extends BaseRequest
{
    public const MAX_LENGTH = 1000;

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:'.self::MAX_LENGTH, 'not_regex:/^\s*$/'],
            'send_at' => [$this->sendAtRequired() ? 'required' : 'nullable', 'date_format:'.SmsReminderSchedulingService::SEND_AT_FORMAT],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function sendAtRequired(): bool
    {
        return true;
    }
}
