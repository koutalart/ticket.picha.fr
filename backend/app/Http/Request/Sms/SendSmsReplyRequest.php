<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class SendSmsReplyRequest extends BaseRequest
{
    public const MAX_LENGTH = 640;

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.self::MAX_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }
}
