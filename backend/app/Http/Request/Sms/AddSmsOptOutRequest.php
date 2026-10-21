<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class AddSmsOptOutRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
        ];
    }
}
