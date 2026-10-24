<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\Http\Request\BaseRequest;

class UpdateOrganizerSmsSettingsRequest extends BaseRequest
{
    public const MAX_INSTRUCTIONS_LENGTH = 2000;

    public const MAX_HOLDING_REPLY_LENGTH = 320;

    public const MAX_RELAY_LABEL_LENGTH = 80;

    public function rules(): array
    {
        return [
            'api_key' => ['sometimes', 'nullable', 'string', 'min:10', 'max:512', 'regex:/^\S+$/'],
            'from_number' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^[0-9+\s().-]+$/'],
            'notify_on_inbound_sms' => ['sometimes', 'boolean'],
            'assistant_enabled' => ['sometimes', 'boolean'],
            'assistant_auto_send' => ['sometimes', 'boolean'],
            'assistant_holding_reply_enabled' => ['sometimes', 'boolean'],
            'sms_default_reminders_enabled' => ['sometimes', 'boolean'],
            'assistant_holding_reply' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HOLDING_REPLY_LENGTH],
            'assistant_instructions' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS_LENGTH],
            'live_chat_enabled' => ['sometimes', 'boolean'],
            'organizer_assistant_enabled' => ['sometimes', 'boolean'],
            'organizer_assistant_instructions' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS_LENGTH],
            'live_chat_relay_label' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_RELAY_LABEL_LENGTH],
        ];
    }
}
