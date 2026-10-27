<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Meta;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Meta\MetaServerEventService;
use Illuminate\Validation\Rule;

class TrackMetaPageEventRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'event_name' => ['required', Rule::in([MetaServerEventService::PAGE_VIEW, MetaServerEventService::VIEW_CONTENT])],
            'meta_event_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-.]{8,100}$/'],
            'consent' => ['required', 'boolean'],
            'event_source_url' => ['nullable', 'string', 'max:2048'],
            'fbp' => ['nullable', 'string', 'max:600'],
            'fbc' => ['nullable', 'string', 'max:600'],
            'fbclid' => ['nullable', 'string', 'max:600'],
        ];
    }
}
