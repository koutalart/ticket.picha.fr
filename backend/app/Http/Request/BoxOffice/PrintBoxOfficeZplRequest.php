<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\Http\Request\BaseRequest;

class PrintBoxOfficeZplRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'printer_host' => ['required', 'ipv4'],
        ];
    }
}
