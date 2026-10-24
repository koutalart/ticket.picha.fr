<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Social;

use HiEvents\Http\Request\BaseRequest;

class UpdateSocialPostRequest extends BaseRequest
{
    use SocialPostRequestRules;

    public function rules(): array
    {
        return $this->contentRules();
    }
}
