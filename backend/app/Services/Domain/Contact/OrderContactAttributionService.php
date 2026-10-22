<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\QrLinkDomainObject;

/**
 * The contact whose personal link brought the order: the ?c= code first, otherwise the contact behind the ?qr= link.
 */
class OrderContactAttributionService
{
    public function __construct(
        private readonly ContactPersonalLinkService $personalLinkService,
    ) {}

    public function resolveContactId(?string $contactCode, int $organizerId, ?QrLinkDomainObject $qrLink): ?int
    {
        $contact = $this->personalLinkService->findByCode($contactCode, $organizerId);

        if ($contact === null && $qrLink?->getContactId() !== null) {
            $contact = $this->personalLinkService->findById($qrLink->getContactId());
        }

        return $contact !== null && $contact->getOrganizerId() === $organizerId ? $contact->getId() : null;
    }
}
