<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactTagDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetContactsHandler
{
    public function __construct(private readonly ContactRepositoryInterface $contactRepository)
    {
    }

    public function handle(int $organizerId, ContactFilterDTO $filter, QueryParamsDTO $params): LengthAwarePaginator
    {
        return $this->contactRepository
            ->loadRelation(ContactTagDomainObject::class)
            ->paginateForOrganizer($organizerId, $filter, $params);
    }
}
