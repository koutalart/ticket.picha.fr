<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class UpdateSmsLineKeywordRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'welcome_message' => ['nullable', 'string', 'max:'.SendSmsReplyRequest::MAX_LENGTH],
        ];
    }
}
