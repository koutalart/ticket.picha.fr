<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\EventAssistant;

use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class ResearchEventDescriptionRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'venue' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(EventCategory::valuesArray())],
            'facts' => ['nullable', 'string', 'max:1000'],
            'draft' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
