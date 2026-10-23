<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class CreateContactNoteRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
        ];
    }
}
