<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class RecordContactLinkVisitRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'referrer' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
