<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class UpdateOrganizerSmsSettingsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'api_key' => ['sometimes', 'nullable', 'string', 'min:10', 'max:512', 'regex:/^\S+$/'],
            'from_number' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^[0-9+\s().-]+$/'],
        ];
    }
}
