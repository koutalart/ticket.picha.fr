<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;

class AppendSocialMediaChunkRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'index' => ['required', 'integer', 'min:0', 'max:100'],
            'chunk' => ['required', 'file', 'max:'.(SocialMediaFileService::CHUNK_BYTES / 1024)],
        ];
    }
}
