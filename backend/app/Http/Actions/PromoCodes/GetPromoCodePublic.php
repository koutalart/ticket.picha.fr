<?php

namespace HiEvents\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPromoCodePublic extends BaseAction
{
    public function __construct(
        private readonly PromoCodeRepositoryInterface    $promoCodeRepository,
        private readonly PromoCodeUsageValidationService $promoCodeUsageValidationService,
    )
    {
    }

    public function __invoke(int $eventId, string $promoCode, Request $request): JsonResponse
    {
        // intentionally not returning a 404
        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::CODE => strtolower(trim($promoCode)),
            PromoCodeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if (!$this->promoCodeUsageValidationService->isPromoCodeUsable($promoCode)) {
            return $this->jsonResponse(['valid' => false]);
        }

        return $this->jsonResponse([
            'valid' => true,
            'discount_type' => $promoCode->getDiscountType(),
            'discount' => $promoCode->getDiscount(),
            'expiry_date' => $promoCode->getExpiryDate()
                ? Carbon::parse($promoCode->getExpiryDate(), 'UTC')->toIso8601ZuluString()
                : null,
            'applicable_product_ids' => $this->applicableProductIds($promoCode->getApplicableProductIds()),
        ]);
    }

    private function applicableProductIds(array|string|null $ids): array
    {
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
}
