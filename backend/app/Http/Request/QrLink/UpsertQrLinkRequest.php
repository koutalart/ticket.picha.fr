<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\QrLink;

use HiEvents\DomainObjects\Enums\QrLinkDestinationType;
use HiEvents\DomainObjects\Enums\QrPosterLogo;
use HiEvents\DomainObjects\Enums\QrPosterTemplate;
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
            'app_store_url' => [
                'nullable',
                'required_if:destination_type,'.QrLinkDestinationType::APP->value,
                'url:https',
                'max:2048',
            ],
            'play_store_url' => [
                'nullable',
                'required_if:destination_type,'.QrLinkDestinationType::APP->value,
                'url:https',
                'max:2048',
            ],
            'event_id' => [
                'nullable',
                'required_if:destination_type,'.QrLinkDestinationType::EVENT->value,
                'integer',
            ],
            'qr_campaign_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'social_links' => ['nullable', 'array', 'max:12'],
            'social_links.*.platform' => ['required', Rule::in(QrLinkDomainObject::SOCIAL_PLATFORMS)],
            'social_links.*.url' => ['required', 'url:http,https', 'max:500'],
            'design' => ['nullable', 'array'],
            'design.foreground' => ['nullable', self::HEX_COLOR],
            'design.background' => ['nullable', self::HEX_COLOR],
            'design.template' => ['nullable', Rule::in(QrPosterTemplate::valuesArray())],
            'design.background_image_id' => ['nullable', 'integer', 'required_if:design.template,'.QrPosterTemplate::CUSTOM->value],
            'design.qr_position' => ['nullable', 'integer', 'between:10,90'],
            'design.qr_scale' => ['nullable', 'integer', 'between:15,80'],
            'design.caption' => ['nullable', 'string', 'max:60'],
            'design.poster_title' => ['nullable', 'string', 'max:40'],
            'design.poster_logo' => ['nullable', Rule::in(QrPosterLogo::valuesArray())],
            'design.caption_color' => ['nullable', self::HEX_COLOR],
            'status' => ['sometimes', Rule::in(QrLinkStatus::valuesArray())],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_url.required_if' => __('Please enter the link the QR code should open.'),
            'event_id.required_if' => __('Please choose an event.'),
            'app_store_url.required_if' => __('Please enter the App Store link.'),
            'play_store_url.required_if' => __('Please enter the Google Play link.'),
            'design.foreground.regex' => __('Colors must be in the #RRGGBB format.'),
            'design.background.regex' => __('Colors must be in the #RRGGBB format.'),
            'design.caption_color.regex' => __('Colors must be in the #RRGGBB format.'),
            'design.background_image_id.required_if' => __('Please upload a background image.'),
        ];
    }
}
