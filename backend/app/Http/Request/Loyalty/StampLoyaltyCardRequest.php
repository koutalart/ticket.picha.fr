<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Loyalty;

use HiEvents\Http\Request\BaseRequest;

class StampLoyaltyCardRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
        ];
    }
}
