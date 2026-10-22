<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsCancellationConfirmationDomainObject;

/**
 * @extends RepositoryInterface<SmsCancellationConfirmationDomainObject>
 */
interface SmsCancellationConfirmationRepositoryInterface extends RepositoryInterface
{
    /**
     * Creates the row while holding a lock on (event, phone), so cancellations processed at the same time see each other.
     * When a confirmation to the same phone for the same event was sent, or is being sent, since $since,
     * the row is created with $skippedAttributes merged in instead.
     */
    public function createUnlessPhoneConfirmedSince(
        array $attributes,
        string $since,
        array $skippedAttributes,
    ): SmsCancellationConfirmationDomainObject;
}
