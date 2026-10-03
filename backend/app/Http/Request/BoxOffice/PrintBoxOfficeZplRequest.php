<?php

namespace HiEvents\Http\Request\BoxOffice;

class PrintBoxOfficeZplRequest extends BoxOfficeZplLabelFormatRequest
{
    public function rules(): array
    {
        return [
            'printer_host' => ['required', 'ipv4'],
            ...parent::rules(),
        ];
    }
}
