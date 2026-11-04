<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;

class RequestSocialPostChangesRequest extends BaseRequest
{
    public const COMMENT_MIN_LENGTH = 3;

    public const COMMENT_MAX_LENGTH = 1000;

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:'.self::COMMENT_MIN_LENGTH, 'max:'.self::COMMENT_MAX_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comment'))) {
            $this->merge(['comment' => trim(strip_tags($this->input('comment')))]);
        }
    }
}
