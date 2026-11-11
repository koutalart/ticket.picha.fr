<?php

namespace HiEvents\Http\Request\Waitlist;

use HiEvents\Http\Request\BaseRequest;

class CreateWaitlistEntryRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'product_price_id' => ['required', 'integer', 'exists:product_prices,id'],
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.max' => __('This phone number is not valid.'),
            'phone.regex' => __('This phone number is not valid.'),
        ];
    }
}
