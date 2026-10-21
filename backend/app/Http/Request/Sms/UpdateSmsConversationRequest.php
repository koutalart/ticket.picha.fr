<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Sms;

use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateSmsConversationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(SmsConversationStatus::valuesArray())],
        ];
    }
}
