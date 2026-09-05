<?php

namespace HiEvents\Resources\BoxOffice;

use HiEvents\Resources\Attendee\AttendeeResource;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeSaleResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoxOfficeSaleResultDTO
 */
class BoxOfficeSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sale_id' => $this->saleId,
            'attendee' => new AttendeeResource($this->attendee),
            'order' => new OrderResource($this->order),
        ];
    }
}
