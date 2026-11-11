<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Waitlist;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\WaitlistEntryStatus;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Helper\Url;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\WaitlistEntryRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderMessageRenderer;
use HiEvents\Services\Domain\Sms\SmsShortLinkService;
use HiEvents\Services\Domain\Sms\SmsWithLinkSender;
use Illuminate\Support\Carbon;

/**
 * Waiting list SMS: the confirmation of the registration and the offer of a freed place.
 * The text first, then the short link alone in a second SMS.
 */
class SmsWaitlistService
{
    public function __construct(
        private readonly WaitlistEntryRepositoryInterface $entryRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductPriceRepositoryInterface $productPriceRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SmsReminderMessageRenderer $renderer,
        private readonly SmsShortLinkService $shortLinkService,
        private readonly SmsWithLinkSender $sender,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    public static function confirmationMessage(CustomerAddressForm $addressForm): string
    {
        return __(
            'Noted, you are on the waiting list for {billet} at {evenement}. I will let you know by SMS as soon as a place frees up. The event: {lien}',
            [],
            $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    public static function offerMessage(CustomerAddressForm $addressForm): string
    {
        return __(
            'Good news {prenom}, a place has freed up for {billet} at {evenement}. It is held for you for a limited time, book it here: {lien}',
            [],
            $addressForm === CustomerAddressForm::TU ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    public function findEntry(int $entryId): ?WaitlistEntryDomainObject
    {
        return $this->entryRepository->findFirst($entryId);
    }

    public function timezoneForEntry(WaitlistEntryDomainObject $entry): string
    {
        return $this->eventRepository->findFirst($entry->getEventId())?->getTimezone() ?: 'UTC';
    }

    public function sendConfirmation(WaitlistEntryDomainObject $entry): bool
    {
        if ($entry->getStatus() !== WaitlistEntryStatus::WAITING->name) {
            return false;
        }

        $event = $this->event($entry);
        if ($event === null) {
            return false;
        }

        return $this->send($entry, $event, self::confirmationMessage($this->addressForm($event)), $event->getEventUrl());
    }

    public function sendOffer(WaitlistEntryDomainObject $entry, string $orderShortId, string $sessionIdentifier): bool
    {
        if ($entry->getStatus() !== WaitlistEntryStatus::OFFERED->name
            || ($entry->getOfferExpiresAt() !== null && Carbon::parse($entry->getOfferExpiresAt())->isPast())) {
            return false;
        }

        $event = $this->event($entry);
        if ($event === null) {
            return false;
        }

        $checkoutUrl = sprintf(
            Url::getPublicFrontEndUrlFromConfig(Url::ORDER_DETAILS, $event, [
                'session_identifier' => $sessionIdentifier,
                'waitlist' => 'true',
            ]),
            $event->getId(),
            $orderShortId,
        );

        return $this->send($entry, $event, self::offerMessage($this->addressForm($event)), $checkoutUrl);
    }

    private function send(WaitlistEntryDomainObject $entry, EventDomainObject $event, string $template, string $url): bool
    {
        $phone = $entry->getPhone() !== null ? SmsPhoneNormalizer::normalize($entry->getPhone()) : null;
        if ($phone === null || $event->getOrganizerId() === null) {
            return false;
        }

        $template = str_replace('{billet}', $this->productName($entry), $template);
        $link = $this->shortLinkService->shorten($event, $url);

        $this->sender->send(new SendSmsDTO(
            organizerId: $event->getOrganizerId(),
            phone: $phone,
            content: $this->renderer->render($template, $event, $entry->getFirstName(), $link),
            authorType: SmsMessageAuthorType::AUTOMATIC,
        ), $link);

        return true;
    }

    private function event(WaitlistEntryDomainObject $entry): ?EventDomainObject
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->findFirst($entry->getEventId());
    }

    private function addressForm(EventDomainObject $event): CustomerAddressForm
    {
        return $this->customerLocaleResolver->addressFormForOrganizerId($event->getOrganizerId());
    }

    private function productName(WaitlistEntryDomainObject $entry): string
    {
        $price = $this->productPriceRepository->findFirst($entry->getProductPriceId());
        $product = $price !== null ? $this->productRepository->findFirst($price->getProductId()) : null;

        $name = trim((string) $product?->getTitle());
        if ($price?->getLabel()) {
            $name .= ' - '.$price->getLabel();
        }

        return $name !== '' ? $name : __('your ticket', [], 'fr');
    }
}
