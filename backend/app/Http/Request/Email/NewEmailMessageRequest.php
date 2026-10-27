<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Email;

use HiEvents\Http\Request\BaseRequest;

class NewEmailMessageRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:'.ReplyEmailThreadRequest::MAX_SUBJECT_LENGTH, 'not_regex:/^\s*$/'],
            'body' => ['required', 'string', 'max:'.ReplyEmailThreadRequest::MAX_BODY_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }
}
