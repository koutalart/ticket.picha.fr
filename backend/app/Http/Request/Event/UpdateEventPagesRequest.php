<?php

namespace HiEvents\Http\Request\Event;

use HiEvents\DomainObjects\Enums\EventArtistKind;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Event\EventPagesService;
use Illuminate\Validation\Rule;

class UpdateEventPagesRequest extends BaseRequest
{
    private const URL_RULE = 'nullable|url:http,https|max:500';

    public function rules(): array
    {
        $linkRules = [];
        foreach (EventPagesService::LINK_TYPES as $type) {
            $linkRules['artists.*.links.'.$type] = self::URL_RULE;
        }

        return [
            'artists' => 'present|array|max:40',
            'artists.*.name' => 'required|string|max:120',
            'artists.*.kind' => ['nullable', Rule::in(EventArtistKind::valuesArray())],
            'artists.*.image_id' => 'nullable|integer',
            'artists.*.bio' => 'nullable|string|max:6000',
            'artists.*.song' => 'nullable|string|max:200',
            'artists.*.links' => 'nullable|array',
            ...$linkRules,

            'practical_info' => 'present|array|max:30',
            'practical_info.*.title' => 'required|string|max:120',
            'practical_info.*.content' => 'nullable|string|max:20000',

            'faq' => 'present|array|max:60',
            'faq.*.question' => 'required|string|max:300',
            'faq.*.answer' => 'required|string|max:10000',
        ];
    }

    public function messages(): array
    {
        return [
            'artists.*.name.required' => __('Each artist needs a name.'),
            'practical_info.*.title.required' => __('Each section needs a title.'),
            'faq.*.question.required' => __('Each question needs a text.'),
            'faq.*.answer.required' => __('Each question needs an answer.'),
        ];
    }
}
