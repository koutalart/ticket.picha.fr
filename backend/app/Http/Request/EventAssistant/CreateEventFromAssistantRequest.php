<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\EventAssistant;

use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateEventFromAssistantRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'organizer_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', Rule::in(EventCategory::valuesArray())],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after' => __('The end date must be after the start date.'),
        ];
    }
}
