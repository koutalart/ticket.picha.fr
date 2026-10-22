<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SmsCancellationConfirmationDomainObject;
use HiEvents\Models\SmsCancellationConfirmation;
use HiEvents\Repository\Interfaces\SmsCancellationConfirmationRepositoryInterface;

/**
 * @extends BaseRepository<SmsCancellationConfirmationDomainObject>
 */
class SmsCancellationConfirmationRepository extends BaseRepository implements SmsCancellationConfirmationRepositoryInterface
{
    protected function getModel(): string
    {
        return SmsCancellationConfirmation::class;
    }

    public function getDomainObject(): string
    {
        return SmsCancellationConfirmationDomainObject::class;
    }
}
