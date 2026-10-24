<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;

class ConnectMetaPageRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'selection_key' => ['required', 'string', 'max:100'],
            'page_id' => ['required', 'string', 'max:64'],
        ];
    }
}
