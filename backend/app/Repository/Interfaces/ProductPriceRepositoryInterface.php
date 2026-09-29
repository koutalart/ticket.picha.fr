<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ProductPriceDomainObject;

/**
 * @extends RepositoryInterface<ProductPriceDomainObject>
 */
interface ProductPriceRepositoryInterface extends RepositoryInterface
{
    /**
     * Locks the row with SELECT ... FOR UPDATE, for callers that must
     * serialize concurrent stock checks within their own transaction
     * (S2, PICHA_BOX_OFFICE_SECURITY_FINDINGS.md).
     */
    public function lockForUpdateById(int $id): ?ProductPriceDomainObject;
}
