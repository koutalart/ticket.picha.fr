<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsRegistrationConfirmationDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SmsRegistrationConfirmationDomainObject>
 */
interface SmsRegistrationConfirmationRepositoryInterface extends RepositoryInterface
{
    /**
     * Confirmations sent since the date while an earlier confirmation had already been sent to the same phone
     * for the same event and that phone was not released yet, for the given organizers.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, SmsRegistrationConfirmationDomainObject>
     */
    public function findSentToAlreadyConfirmedPhoneSince(array $organizerIds, string $since, int $limit): Collection;
}
