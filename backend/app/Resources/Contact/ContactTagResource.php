<?php

namespace HiEvents\Resources\Contact;

use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin ContactTagDomainObject
 */
class ContactTagResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
        ];
    }
}
