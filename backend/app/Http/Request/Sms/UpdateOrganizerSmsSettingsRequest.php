<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use Closure;
use HiEvents\DomainObjects\Enums\LiveChatStyle;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Sms\CheckInWelcome\SmsCheckInWelcomeMessageGenerator;
use Illuminate\Validation\Rule;

class UpdateOrganizerSmsSettingsRequest extends BaseRequest
{
    public const MAX_INSTRUCTIONS_LENGTH = 2000;

    public const MAX_HOLDING_REPLY_LENGTH = 320;

    public const MAX_RELAY_LABEL_LENGTH = 80;

    public const MAX_WELCOME_TEMPLATES_LENGTH = 8000;

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
            'checkin_welcome_sms_enabled' => ['sometimes', 'boolean'],
            'checkin_welcome_sms_prompt' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS_LENGTH],
            'checkin_welcome_sms_templates' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_WELCOME_TEMPLATES_LENGTH, self::welcomeTemplatesRule(...)],
            'live_chat_style' => ['sometimes', Rule::in(LiveChatStyle::valuesArray())],
            'organizer_assistant_enabled' => ['sometimes', 'boolean'],
            'organizer_assistant_instructions' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS_LENGTH],
            'live_chat_relay_label' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_RELAY_LABEL_LENGTH],
        ];
    }

    /**
     * Each line is one welcome SMS template: the first one breaking a rule is reported with its line number.
     */
    public static function welcomeTemplatesRule(string $attribute, mixed $value, Closure $fail): void
    {
        $lines = preg_split('/\R/u', (string) $value) ?: [];
        $count = 0;

        foreach ($lines as $index => $line) {
            $template = trim($line);
            if ($template === '') {
                continue;
            }

            if (++$count > SmsCheckInWelcomeMessageGenerator::MAX_ORGANIZER_TEMPLATES) {
                $fail(__('At most :max templates', ['max' => SmsCheckInWelcomeMessageGenerator::MAX_ORGANIZER_TEMPLATES]));

                return;
            }

            $violation = SmsCheckInWelcomeMessageGenerator::templateViolation($template);
            if ($violation !== null) {
                $fail(__('Line :line: :reason', ['line' => $index + 1, 'reason' => $violation]));

                return;
            }
        }
    }
}
