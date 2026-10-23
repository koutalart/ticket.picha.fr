<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer;

use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\NewsletterSubscriberSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetOrganizerNewsletterSubscribersHandler
{
    public const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly OrganizerNewsletterSubscriberRepositoryInterface $subscriberRepository,
    )
    {
    }

    public function handle(int $organizerId, NewsletterSubscriberSearchDTO $search): LengthAwarePaginator
    {
        return $this->subscriberRepository->searchForOrganizer($organizerId, $search);
    }

    public function handleExport(int $organizerId, ?string $query): LengthAwarePaginator
    {
        return $this->subscriberRepository->searchForOrganizer($organizerId, new NewsletterSubscriberSearchDTO(
            query: $query,
            page: 1,
            perPage: self::EXPORT_LIMIT,
        ));
    }
}
