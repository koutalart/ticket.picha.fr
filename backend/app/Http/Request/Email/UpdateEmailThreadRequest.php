<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Email;

use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateEmailThreadRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(EmailThreadStatus::valuesArray())],
        ];
    }
}
