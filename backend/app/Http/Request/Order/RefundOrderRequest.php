<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\DomainObjects\Enums\RefundReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'notify_buyer' => 'required|boolean',
            'cancel_order' => 'required|boolean',
            'attendee_ids' => 'sometimes|array',
            'attendee_ids.*' => 'integer',
            'reason' => ['sometimes', 'nullable', Rule::in(RefundReason::valuesArray())],
        ];
    }
}
