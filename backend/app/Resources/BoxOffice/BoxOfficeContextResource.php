<?php

namespace HiEvents\Resources\BoxOffice;

use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeContextEventDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoxOfficeContextEventDTO
 */
class BoxOfficeContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'country' => $this->country,
            'calling_code' => $this->calling_code,
        ];
    }
}
