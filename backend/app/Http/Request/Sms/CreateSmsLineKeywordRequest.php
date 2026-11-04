<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class CreateSmsLineKeywordRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'max:40', 'not_regex:/^\s*$/'],
            'welcome_message' => ['nullable', 'string', 'max:'.SendSmsReplyRequest::MAX_LENGTH],
        ];
    }
}
