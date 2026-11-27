<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Loyalty;

use HiEvents\Http\Request\BaseRequest;

class UpsertLoyaltyProgramRequest extends BaseRequest
{
    public function rules(): array
    {
        $color = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'name' => ['required', 'string', 'max:80'],
            'reward_description' => ['required', 'string', 'max:120'],
            'stamps_required' => ['required', 'integer', 'min:2', 'max:50'],
            'background_color' => $color,
            'foreground_color' => $color,
            'label_color' => $color,
            'logo_url' => ['nullable', 'url:https', 'max:500'],
            'locations' => ['present', 'array', 'max:10'],
            'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.relevant_text' => ['nullable', 'string', 'max:120'],
            'max_distance' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'beacon_uuid' => ['nullable', 'uuid'],
            'beacon_major' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'beacon_minor' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'beacon_relevant_text' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'locations.max' => __('A loyalty card can have at most 10 locations.'),
            'background_color.regex' => __('Use a color such as #1F2937.'),
            'foreground_color.regex' => __('Use a color such as #1F2937.'),
            'label_color.regex' => __('Use a color such as #1F2937.'),
        ];
    }
}
