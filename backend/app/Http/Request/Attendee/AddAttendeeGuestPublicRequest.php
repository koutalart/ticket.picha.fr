<?php

namespace HiEvents\Http\Request\Attendee;

use HiEvents\Helper\CustomerLocale;
use HiEvents\Http\Request\BaseRequest;

class AddAttendeeGuestPublicRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
        ];
    }

    public function messages(): array
    {
        $locale = CustomerLocale::get();

        return [
            'first_name.required' => __('Please enter the first name of your guest.', [], $locale),
            'first_name.max' => __('The first name is too long.', [], $locale),
            'last_name.required' => __('Please enter the last name of your guest.', [], $locale),
            'last_name.max' => __('The last name is too long.', [], $locale),
            'phone.max' => __('This phone number is not valid.', [], $locale),
            'phone.regex' => __('This phone number is not valid.', [], $locale),
        ];
    }
}
