<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\CancellationConfirmation;

use HiEvents\DomainObjects\EventDomainObject;

/**
 * Personal link inserted through {lien} in the cancellation SMS, so the person can register again.
 * Null when no personal link can be built for this attendee: {lien} and its label are then removed.
 */
interface SmsCancellationLinkProviderInterface
{
    public function findForAttendee(int $attendeeId, EventDomainObject $event): ?string;
}
