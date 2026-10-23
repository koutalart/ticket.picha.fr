<?php

namespace HiEvents\Http\Request\Attendee;

use HiEvents\Http\Request\BaseRequest;

class VerifyAttendanceAccessCodeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'max:190'],
            'code' => ['required', 'string', 'max:12'],
        ];
    }
}
