<?php

namespace Tests\Unit\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\Http\Actions\PromoCodes\GetPromoCodePublic;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GetPromoCodePublicTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_valid_code_exposes_discount_expiry_and_products(): void
    {
        $expiry = Carbon::now('UTC')->addDays(2)->startOfSecond();

        $data = $this->fetchCode((new PromoCodeDomainObject)
            ->setId(3)
            ->setCode('innocentevent')
            ->setDiscountType('FIXED')
            ->setDiscount(5.0)
            ->setExpiryDate($expiry->toDateTimeString())
            ->setApplicableProductIds(['10']));

        $this->assertTrue($data['valid']);
        $this->assertSame('FIXED', $data['discount_type']);
        $this->assertSame(5.0, (float) $data['discount']);
        $this->assertSame([10], $data['applicable_product_ids']);
        $this->assertTrue(Carbon::parse($data['expiry_date'])->equalTo($expiry));
    }

    public function test_code_without_expiry_or_product_restriction(): void
    {
        $data = $this->fetchCode((new PromoCodeDomainObject)
            ->setId(3)
            ->setCode('bienvenue')
            ->setDiscountType('PERCENTAGE')
            ->setDiscount(10.0));

        $this->assertTrue($data['valid']);
        $this->assertNull($data['expiry_date']);
        $this->assertSame([], $data['applicable_product_ids']);
    }

    public function test_expired_code_only_says_invalid(): void
    {
        $data = $this->fetchCode((new PromoCodeDomainObject)
            ->setId(3)
            ->setCode('innocentevent')
            ->setDiscountType('FIXED')
            ->setDiscount(5.0)
            ->setExpiryDate(Carbon::now('UTC')->subMinute()->toDateTimeString()));

        $this->assertSame(['valid' => false], $data);
    }

    public function test_unknown_code_only_says_invalid(): void
    {
        $this->assertSame(['valid' => false], $this->fetchCode(null));
    }

    private function fetchCode(?PromoCodeDomainObject $promoCode): array
    {
        $repository = Mockery::mock(PromoCodeRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')->andReturn($promoCode);

        $orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $orderRepository->shouldReceive('countActivePromoCodeUsage')->andReturn(0);

        $response = (new GetPromoCodePublic($repository, new PromoCodeUsageValidationService($orderRepository)))
            ->__invoke(7, 'INNOCENTEVENT', Request::create('/'));

        return $response->getData(true);
    }
}
