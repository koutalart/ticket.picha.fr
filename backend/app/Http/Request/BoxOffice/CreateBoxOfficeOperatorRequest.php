<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\Http\Request\BaseRequest;

class CreateBoxOfficeOperatorRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:40'],
            'last_name' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email'],
        ];
    }
}
