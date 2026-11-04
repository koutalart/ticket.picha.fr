<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Domain\Sms\DTO\SmsLineKeywordsViewDTO;

/**
 * @mixin SmsLineKeywordsViewDTO
 */
class SmsLineKeywordsResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'available' => $this->available,
            'line_number' => $this->lineNumber,
            'keywords' => SmsLineKeywordResource::collection($this->keywords)->resolve($request),
        ];
    }
}
