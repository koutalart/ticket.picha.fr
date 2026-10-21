<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactTimelineItemDTO;
use Illuminate\Support\Collection;

class GetContactTimelineHandler
{
    public function __construct(
        private readonly ContactLoader $contactLoader,
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    /**
     * @return Collection<int, ContactTimelineItemDTO>
     *
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $contactId): Collection
    {
        $contact = $this->contactLoader->load($organizerId, $contactId);

        if ($contact->getEmail() === null) {
            return collect();
        }

        return $this->contactRepository->getTimeline($organizerId, $contact->getEmail());
    }
}
