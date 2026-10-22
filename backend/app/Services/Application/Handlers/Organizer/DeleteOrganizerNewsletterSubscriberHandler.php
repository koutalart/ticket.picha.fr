<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;

class DeleteOrganizerNewsletterSubscriberHandler
{
    public function __construct(
        private readonly OrganizerNewsletterSubscriberRepositoryInterface $subscriberRepository,
    )
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $subscriberId): void
    {
        $deleted = $this->subscriberRepository->deleteWhere([
            'id' => $subscriberId,
            'organizer_id' => $organizerId,
        ]);

        if ($deleted === 0) {
            throw new ResourceNotFoundException(__('Subscriber not found'));
        }
    }
}
