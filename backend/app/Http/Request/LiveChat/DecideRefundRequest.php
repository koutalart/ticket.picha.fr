<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\LiveChat;

use HiEvents\DomainObjects\Enums\RefundDecision;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class DecideRefundRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(RefundDecision::valuesArray())],
            'token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
