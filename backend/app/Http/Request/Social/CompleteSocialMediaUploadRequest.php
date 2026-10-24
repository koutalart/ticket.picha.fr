<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CompleteSocialMediaUploadRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'format' => ['nullable', Rule::in(SocialPostFormat::valuesArray())],
        ];
    }
}
