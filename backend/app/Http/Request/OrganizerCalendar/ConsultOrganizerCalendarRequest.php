<?php

namespace HiEvents\Http\Request\OrganizerCalendar;

use HiEvents\Helper\CustomerLocale;
use HiEvents\Http\Request\BaseRequest;

class ConsultOrganizerCalendarRequest extends BaseRequest
{
    public const DEVICE_PATTERN = '/^[A-Za-z0-9-]{8,64}$/D';

    protected function prepareForValidation(): void
    {
        $name = $this->input('prenom');

        if (is_string($name)) {
            $name = strip_tags($name);
            $name = (string) preg_replace('/[\p{C}<>]+/u', ' ', $name);
            $name = trim((string) preg_replace('/\s+/u', ' ', $name));

            $this->merge(['prenom' => $name]);
        }
    }

    public function rules(): array
    {
        return [
            'prenom' => ['required', 'string', 'min:1', 'max:60'],
            'appareil' => ['required', 'string', 'regex:'.self::DEVICE_PATTERN],
        ];
    }

    public function messages(): array
    {
        $locale = CustomerLocale::get();

        return [
            'prenom.required' => __('Please enter your first name.', [], $locale),
            'prenom.max' => __('Your first name must be 60 characters or fewer.', [], $locale),
            'appareil.required' => __('This device could not be identified. Reload the page.', [], $locale),
            'appareil.regex' => __('This device could not be identified. Reload the page.', [], $locale),
        ];
    }
}
