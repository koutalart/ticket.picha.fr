<?php

namespace HiEvents\Resources\BoxOffice;

use HiEvents\DomainObjects\EventDomainObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventDomainObject
 */
class BoxOfficeContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'currency' => $this->getCurrency(),
            'timezone' => $this->getTimezone(),
        ];
    }
}
