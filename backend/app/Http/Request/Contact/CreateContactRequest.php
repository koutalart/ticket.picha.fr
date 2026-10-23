<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class CreateContactRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'email_consent' => ['required', 'boolean'],
            'sms_consent' => ['required', 'boolean'],
        ];
    }
}
