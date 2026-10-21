<?php

namespace HiEvents\Http\Request\BoxOffice;

class EnqueueKioskPrintJobsRequest extends BoxOfficeZplLabelFormatRequest
{
    public function rules(): array
    {
        return [
            'attendee_public_ids' => ['required', 'array', 'min:1', 'max:50'],
            'attendee_public_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            ...parent::rules(),
        ];
    }
}
