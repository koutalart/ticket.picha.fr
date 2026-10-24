<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Social\Feed\SocialCommentService;

class ReplyToSocialCommentRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.SocialCommentService::MAX_REPLY_LENGTH, 'not_regex:/^\s*$/'],
        ];
    }
}
