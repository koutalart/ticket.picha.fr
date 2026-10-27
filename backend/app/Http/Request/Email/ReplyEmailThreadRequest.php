<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Email;

use HiEvents\Http\Request\BaseRequest;

class ReplyEmailThreadRequest extends BaseRequest
{
    public const MAX_BODY_LENGTH = 10000;

    public const MAX_SUBJECT_LENGTH = 200;

    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:'.self::MAX_SUBJECT_LENGTH],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }
}
