<?php

namespace HiEvents\Http\Request\Attendee;

use HiEvents\Http\Request\BaseRequest;

class RequestAttendanceAccessCodeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'max:190'],
        ];
    }
}
