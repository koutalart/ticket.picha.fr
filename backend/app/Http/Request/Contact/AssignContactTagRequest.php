<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class AssignContactTagRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'contact_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'contact_ids.*' => ['integer'],
        ];
    }
}
