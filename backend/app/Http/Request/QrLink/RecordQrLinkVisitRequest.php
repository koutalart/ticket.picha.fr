<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkVisitType;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class RecordQrLinkVisitRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(QrLinkVisitType::valuesArray())],
            'target' => ['nullable', 'string', 'max:30'],
            'referrer' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
