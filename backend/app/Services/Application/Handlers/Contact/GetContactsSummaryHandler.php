<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactFilterDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactSummaryDTO;

class GetContactsSummaryHandler
{
    public function __construct(private readonly ContactRepositoryInterface $contactRepository)
    {
    }

    public function handle(int $organizerId, ContactFilterDTO $filter): ContactSummaryDTO
    {
        return $this->contactRepository->getSummaryForOrganizer($organizerId, $filter);
    }
}
