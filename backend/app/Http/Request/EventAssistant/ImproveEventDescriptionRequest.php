<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\EventAssistant;

use HiEvents\Http\Request\BaseRequest;

class ImproveEventDescriptionRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'title' => ['nullable', 'string', 'max:150'],
            'venue' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => __('Write a few words about your event first.'),
        ];
    }
}
