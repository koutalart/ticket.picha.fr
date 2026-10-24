<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateSocialPostRequest extends BaseRequest
{
    use SocialPostRequestRules;

    public function rules(): array
    {
        return array_merge($this->contentRules(), [
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['required', 'distinct', Rule::in(SocialNetwork::valuesArray())],
            'format' => ['required', Rule::in(SocialPostFormat::valuesArray())],
            'as_draft' => ['sometimes', 'boolean'],
        ]);
    }
}
