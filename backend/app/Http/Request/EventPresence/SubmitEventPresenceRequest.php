<?php

namespace HiEvents\Http\Request\EventPresence;

use HiEvents\Http\Request\BaseRequest;

class SubmitEventPresenceRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'prenom' => ['required', 'string', 'max:40'],
            'nom' => ['nullable', 'string', 'max:60'],
            'telephone' => ['required', 'string', 'max:25'],
            'staff' => ['nullable', 'string', 'max:30'],
        ];
    }
}
