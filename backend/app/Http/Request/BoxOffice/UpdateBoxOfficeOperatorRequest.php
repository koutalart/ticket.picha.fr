<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\DomainObjects\Status\BoxOfficeOperatorStatus;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateBoxOfficeOperatorRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(BoxOfficeOperatorStatus::valuesArray())],
        ];
    }
}
