<?php

namespace HiEvents\Http\Request\Organizer\Font;

use HiEvents\Http\Request\BaseRequest;

class UpdateOrganizerFontFamilyRequest extends BaseRequest
{
    public const FAMILY_NAME_REGEX = '/^[\pL\pN][\pL\pN _.\-]{0,99}$/u';

    public function rules(): array
    {
        return [
            'family_name' => ['required', 'string', 'max:100', 'regex:'.self::FAMILY_NAME_REGEX],
        ];
    }

    public function messages(): array
    {
        return [
            'family_name.regex' => __('The font name may only contain letters, numbers, spaces, dots, hyphens and underscores.'),
        ];
    }
}
