<?php

namespace HiEvents\Http\Request\Organizer\Font;

use HiEvents\DomainObjects\Enums\OrganizerFontFormat;
use HiEvents\DomainObjects\Enums\OrganizerFontWeight;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use Illuminate\Validation\Rule;

class UploadOrganizerFontRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'font_file' => [
                'required',
                'file',
                'max:'.OrganizerFontService::MAX_FILE_SIZE_KB,
                'extensions:'.implode(',', OrganizerFontFormat::ALLOWED_EXTENSIONS),
            ],
            'weight' => ['required', 'integer', Rule::in(OrganizerFontWeight::valuesArray())],
            'family_name' => [
                'required',
                'string',
                'max:100',
                'regex:'.UpdateOrganizerFontFamilyRequest::FAMILY_NAME_REGEX,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'font_file.max' => __('The font file must not be larger than 2 MB.'),
            'font_file.extensions' => __('Accepted formats: WOFF2, WOFF, TTF, OTF.'),
            'family_name.regex' => __('The font name may only contain letters, numbers, spaces, dots, hyphens and underscores.'),
        ];
    }
}
