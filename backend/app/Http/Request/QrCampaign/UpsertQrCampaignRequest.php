<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\QrCampaign;

use HiEvents\Http\Request\BaseRequest;

class UpsertQrCampaignRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
        ];
    }
}
