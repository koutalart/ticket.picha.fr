<?php

namespace HiEvents\Http\Request\OrganizerCalendar;

use HiEvents\Http\Request\BaseRequest;

class VerifyOrganizerCalendarCodeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:12'],
        ];
    }
}
