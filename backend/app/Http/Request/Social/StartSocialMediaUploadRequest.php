<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Social\Media\SocialMediaRules;

class StartSocialMediaUploadRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1', 'max:'.SocialMediaRules::MAX_VIDEO_BYTES],
        ];
    }
}
