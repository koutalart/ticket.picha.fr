<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class PreviewSmsAssistantRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.SendSmsReplyRequest::MAX_LENGTH, 'not_regex:/^\s*$/'],
            'instructions' => ['nullable', 'string', 'max:'.UpdateOrganizerSmsSettingsRequest::MAX_INSTRUCTIONS_LENGTH],
        ];
    }
}
