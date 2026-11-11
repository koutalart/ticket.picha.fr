<?php

namespace Tests\Unit\DomainObjects;

use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use Tests\TestCase;

class EventPublicPromoCodeKindTest extends TestCase
{
    private function code(string $type, ?string $expiry = null): PromoCodeDomainObject
    {
        return (new PromoCodeDomainObject())
            ->setDiscountType($type)
            ->setDiscount($type === PromoCodeDiscountTypeEnum::NONE->name ? 0 : 10)
            ->setExpiryDate($expiry);
    }

    public function testUnknownWhenPromoCodesNotLoaded(): void
    {
        $this->assertNull((new EventDomainObject())->getPublicPromoCodeKind());
    }

    public function testNoneWhenNoActiveCode(): void
    {
        $event = (new EventDomainObject())->setPromoCodes(collect([
            $this->code(PromoCodeDiscountTypeEnum::PERCENTAGE->name, '2020-01-01 00:00:00'),
        ]));

        $this->assertSame('NONE', $event->getPublicPromoCodeKind());
    }

    public function testInvitationWhenAllActiveCodesUnlockOnly(): void
    {
        $event = (new EventDomainObject())->setPromoCodes(collect([
            $this->code(PromoCodeDiscountTypeEnum::NONE->name),
            $this->code(PromoCodeDiscountTypeEnum::NONE->name),
            $this->code(PromoCodeDiscountTypeEnum::PERCENTAGE->name, '2020-01-01 00:00:00'),
        ]));

        $this->assertSame('INVITATION', $event->getPublicPromoCodeKind());
    }

    public function testDiscountWhenAnyActiveCodeDiscounts(): void
    {
        $event = (new EventDomainObject())->setPromoCodes(collect([
            $this->code(PromoCodeDiscountTypeEnum::NONE->name),
            $this->code(PromoCodeDiscountTypeEnum::FIXED->name),
        ]));

        $this->assertSame('DISCOUNT', $event->getPublicPromoCodeKind());
    }
}
