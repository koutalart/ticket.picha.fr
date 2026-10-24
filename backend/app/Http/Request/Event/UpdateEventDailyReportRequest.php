<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Event;

use HiEvents\Http\Request\BaseRequest;

class UpdateEventDailyReportRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'send_time' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'copy_supervisors' => ['sometimes', 'boolean'],
        ];
    }
}
