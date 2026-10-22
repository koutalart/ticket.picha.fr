<?php

namespace HiEvents\Services\Application\Handlers\Organizer;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerNewsletterSubscriberRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\SubscribeToOrganizerNewsletterDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Str;

class SubscribeToOrganizerNewsletterHandler
{
    private const MAX_CONSENT_TEXT_LENGTH = 1000;
    private const MAX_USER_AGENT_LENGTH = 512;

    public function __construct(
        private readonly EventRepositoryInterface                         $eventRepository,
        private readonly OrganizerNewsletterSubscriberRepositoryInterface $subscriberRepository,
        private readonly HtmlPurifierService                              $purifier,
    )
    {
    }

    /**
     * @return bool true when a new subscriber was stored, false when the address was already subscribed
     * @throws ResourceNotFoundException
     */
    public function handle(SubscribeToOrganizerNewsletterDTO $dto): bool
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(OrganizerDomainObject::class)
            ->findFirst($dto->event_id);

        $settings = $event?->getEventSettings();
        $footerSettings = $settings?->getHomepageFooterSettings();
        $footerSettings = is_string($footerSettings) ? json_decode($footerSettings, true) : $footerSettings;

        if (
            $event === null
            || $event->getStatus() !== EventStatus::LIVE->name
            || !$settings?->getRichFooterEnabled()
            || (is_array($footerSettings) && ($footerSettings['newsletter_enabled'] ?? true) === false)
        ) {
            throw new ResourceNotFoundException(__('Newsletter not available'));
        }

        return $this->subscriberRepository->createIfNotSubscribed([
            'organizer_id' => $event->getOrganizerId(),
            'event_id' => $event->getId(),
            'email' => Str::lower(trim($dto->email)),
            'consent_text' => $this->consentText($dto->consent_text, $event),
            'consented_at' => now(),
            'ip' => $dto->ip,
            'user_agent' => $dto->user_agent !== null ? Str::limit($dto->user_agent, self::MAX_USER_AGENT_LENGTH, '') : null,
        ]);
    }

    private function consentText(?string $submitted, EventDomainObject $event): string
    {
        $clean = trim(strip_tags((string)$this->purifier->purify($submitted ?? '')));

        if ($clean !== '') {
            return Str::limit($clean, self::MAX_CONSENT_TEXT_LENGTH, '');
        }

        return __('I agree to receive news from :organizer by e-mail. I can unsubscribe at any time.', [
            'organizer' => $event->getOrganizer()?->getName() ?? '',
        ]);
    }
}
