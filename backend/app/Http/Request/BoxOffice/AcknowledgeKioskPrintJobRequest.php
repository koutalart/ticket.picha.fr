<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\DomainObjects\Status\KioskPrintJobStatus;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class AcknowledgeKioskPrintJobRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([KioskPrintJobStatus::SENT->name, KioskPrintJobStatus::FAILED->name])],
            'error' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
