<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\LoyaltyCardDomainObject;
use HiEvents\Models\LoyaltyCard;
use HiEvents\Repository\Interfaces\LoyaltyCardRepositoryInterface;

/**
 * @extends BaseRepository<LoyaltyCardDomainObject>
 */
class LoyaltyCardRepository extends BaseRepository implements LoyaltyCardRepositoryInterface
{
    public function lockForUpdateById(int $id): ?LoyaltyCardDomainObject
    {
        $model = $this->model->where('id', $id)->lockForUpdate()->first();
        $this->resetModel();

        return $this->handleSingleResult($model);
    }

    protected function getModel(): string
    {
        return LoyaltyCard::class;
    }

    public function getDomainObject(): string
    {
        return LoyaltyCardDomainObject::class;
    }
}
