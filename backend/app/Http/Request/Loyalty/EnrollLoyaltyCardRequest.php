<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Loyalty;

use HiEvents\Http\Request\BaseRequest;

class EnrollLoyaltyCardRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:25', 'required_without:email'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => __('Enter your email address or your phone number.'),
            'phone.required_without' => __('Enter your email address or your phone number.'),
        ];
    }
}
