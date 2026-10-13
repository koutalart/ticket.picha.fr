<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\QrLinkDomainObject;
use HiEvents\DomainObjects\Status\QrLinkStatus;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpsertQrLinkRequest extends BaseRequest
{
    private const HEX_COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'destination_type' => ['required', Rule::in(QrLinkDestinationType::valuesArray())],
            'destination_url' => [
                'nullable',
                'required_if:destination_type,'.QrLinkDestinationType::URL->value,
                'url:http,https',
                'max:2048',
            ],
            'event_id' => [
                'nullable',
                'required_if:destination_type,'.QrLinkDestinationType::EVENT->value,
                'integer',
            ],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'social_links' => ['nullable', 'array', 'max:12'],
            'social_links.*.platform' => ['required', Rule::in(QrLinkDomainObject::SOCIAL_PLATFORMS)],
            'social_links.*.url' => ['required', 'url:http,https', 'max:500'],
            'design' => ['nullable', 'array'],
            'design.foreground' => ['nullable', self::HEX_COLOR],
            'design.background' => ['nullable', self::HEX_COLOR],
            'design.show_logo' => ['nullable', 'boolean'],
            'status' => ['sometimes', Rule::in(QrLinkStatus::valuesArray())],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_url.required_if' => __('Please enter the link the QR code should open.'),
            'event_id.required_if' => __('Please choose an event.'),
            'design.foreground.regex' => __('Colors must be in the #RRGGBB format.'),
            'design.background.regex' => __('Colors must be in the #RRGGBB format.'),
        ];
    }
}
