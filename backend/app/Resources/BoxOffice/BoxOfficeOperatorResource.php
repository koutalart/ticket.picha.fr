<?php

namespace HiEvents\Resources\BoxOffice;

use HiEvents\DomainObjects\EventBoxOfficeOperatorDomainObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventBoxOfficeOperatorDomainObject
 */
class BoxOfficeOperatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->getUserId(),
            'event_id' => $this->getEventId(),
            'status' => $this->getStatus(),
            'first_name' => $this->getUser()?->getFirstName(),
            'last_name' => $this->getUser()?->getLastName(),
            'email' => $this->getUser()?->getEmail(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
