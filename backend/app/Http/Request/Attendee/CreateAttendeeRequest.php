<?php

namespace HiEvents\Http\Request\Attendee;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use Illuminate\Validation\Rule;

class CreateAttendeeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['int', 'required'],
            'product_price_id' => ['int', 'nullable', 'required'],
            'email' => ['required', 'email'],
            'first_name' => ['string', 'required', 'max:40'],
            'last_name' => ['string', 'max:40'],
            'is_free' => ['boolean'],
            'send_confirmation_email' => ['required', 'boolean'],
            'locale' => ['required', Rule::in(Locale::getSupportedLocales())],
        ];
    }
}
