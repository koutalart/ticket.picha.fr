<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class UpsertSmsReplyTemplateRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'not_regex:/^\s*$/'],
            'content' => ['required', 'string', 'max:'.SendSmsReplyRequest::MAX_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }
}
