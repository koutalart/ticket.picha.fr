<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\DomainObjects\Enums\ContactImportField;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class RunContactImportRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'import_token' => ['required', 'uuid'],
            'file_name' => ['required', 'string', 'max:255'],
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['required', Rule::in(ContactImportField::valuesArray())],
            'tag_name' => ['nullable', 'string', 'max:100'],
            'email_consent' => ['required', 'boolean'],
            'sms_consent' => ['required', 'boolean'],
            'default_country' => ['nullable', Rule::in(['FR', 'YT', 'RE', 'MG', 'DK'])],
            'dry_run' => ['required', 'boolean'],
        ];
    }
}
