<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsOptOutSearchDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SmsOptOutDomainObject>
 */
interface SmsOptOutRepositoryInterface extends RepositoryInterface
{
    public function searchForOrganizer(int $organizerId, SmsOptOutSearchDTO $search): LengthAwarePaginator;

    /**
     * STOP recorded for a Mayotte or La Réunion number written with +33.
     *
     * @return Collection<int, SmsOptOutDomainObject>
     */
    public function findWithFrenchOverseasPhones(): Collection;
}
