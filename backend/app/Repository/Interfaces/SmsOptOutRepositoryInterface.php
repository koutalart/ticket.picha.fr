<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsOptOutSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<SmsOptOutDomainObject>
 */
interface SmsOptOutRepositoryInterface extends RepositoryInterface
{
    public function searchForOrganizer(int $organizerId, SmsOptOutSearchDTO $search): LengthAwarePaginator;
}
