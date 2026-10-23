<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

class PreviewSmsReminderRequest extends SmsReminderRequest
{
    protected function sendAtRequired(): bool
    {
        return false;
    }
}
