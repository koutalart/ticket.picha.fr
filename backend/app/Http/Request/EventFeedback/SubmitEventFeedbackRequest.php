<?php

namespace HiEvents\Http\Request\EventFeedback;

use HiEvents\Http\Request\BaseRequest;

class SubmitEventFeedbackRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'integer', 'min:1', 'max:5'],
            'c' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'titre' => ['nullable', 'string', 'max:80'],
            'prenom' => ['nullable', 'string', 'max:40'],
            'artistes' => ['nullable', 'string', 'max:80'],
            'accueil' => ['nullable', 'string', 'max:80'],
            'revenir' => ['nullable', 'string', 'max:80'],
            'mot' => ['nullable', 'string', 'max:600'],
            'publier' => ['nullable', 'string', 'max:10'],
        ];
    }
}
