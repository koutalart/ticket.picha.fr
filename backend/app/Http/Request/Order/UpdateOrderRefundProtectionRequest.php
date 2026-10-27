<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;

class UpdateOrderRefundProtectionRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
