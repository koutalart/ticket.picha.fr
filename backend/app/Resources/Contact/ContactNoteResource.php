<?php

namespace HiEvents\Resources\Contact;

use HiEvents\DomainObjects\ContactNoteDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin ContactNoteDomainObject
 */
class ContactNoteResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'note' => $this->getNote(),
            'author' => $this->getUser()?->getFullName(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
