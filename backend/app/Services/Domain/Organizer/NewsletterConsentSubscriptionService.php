<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Organizer;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

/**
 * Only the buyer ticks the marketing opt-in at checkout (orders.opted_into_marketing_at, the same
 * source the CRM contacts use), so only the order e-mail is subscribed. Attendees whose e-mail
 * differs never gave consent themselves and are never added.
 */
class NewsletterConsentSubscriptionService
{
    public function __construct(
        private readonly OrganizerNewsletterSubscriberRepositoryInterface $subscriberRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    public function subscribeFromOrder(int $orderId): int
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirst($orderId);
        if ($order === null || $order->getOptedIntoMarketingAt() === null) {
            return 0;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirst($order->getEventId());
        if ($event === null || $event->getOrganizerId() === null) {
            return 0;
        }

        return $this->subscribeOrganizer($event->getOrganizerId(), $orderId);
    }

    public function subscribeOrganizer(int $organizerId, ?int $orderId = null, bool $dryRun = false): int
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirst($organizerId);
        if ($organizer === null) {
            return 0;
        }

        return $this->subscriberRepository->subscribeConsentingBuyers(
            organizerId: $organizerId,
            consentText: $this->consentText($organizer),
            orderId: $orderId,
            dryRun: $dryRun,
        );
    }

    /**
     * @return array<int, int> organizer id => subscribers added (or that would be added)
     */
    public function subscribeAllOrganizers(bool $dryRun = false): array
    {
        $results = [];

        foreach ($this->organizerRepository->all(['id']) as $organizer) {
            $results[$organizer->getId()] = $this->subscribeOrganizer($organizer->getId(), dryRun: $dryRun);
        }

        return $results;
    }

    private function consentText(OrganizerDomainObject $organizer): string
    {
        return __(
            'Keep me updated on news and events from :organizer',
            ['organizer' => $organizer->getName()],
            CustomerLocale::get(),
        );
    }
}
