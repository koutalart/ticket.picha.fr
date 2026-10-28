<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Validators\Rules\ProductQuestionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProductQuestionRuleOptionalLastNameTest extends TestCase
{
    public function test_attendee_without_last_name_is_accepted(): void
    {
        $validator = $this->validate($this->attendee(['last_name' => null]));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_attendee_with_empty_last_name_is_accepted(): void
    {
        $validator = $this->validate($this->attendee(['last_name' => '']));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_attendee_first_name_is_still_required(): void
    {
        $validator = $this->validate($this->attendee(['first_name' => null, 'last_name' => null]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('products.0.first_name', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('products.0.last_name', $validator->errors()->toArray());
    }

    private function attendee(array $overrides = []): array
    {
        return array_merge([
            'product_id' => 20,
            'product_price_id' => 30,
            'first_name' => 'Jo',
            'last_name' => 'K',
            'email' => 'jo@example.com',
            'questions' => [],
        ], $overrides);
    }

    private function validate(array $attendee): \Illuminate\Validation\Validator
    {
        $price = (new ProductPriceDomainObject)->setId(30)->setProductId(20)->setPrice(15);

        /** @var ProductDomainObject $product */
        $product = ProductDomainObject::hydrateFromArray([
            'id' => 20,
            'event_id' => 7,
            'title' => 'Vente flash',
            'order' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'type' => 'PAID',
            'product_type' => ProductType::TICKET->name,
            'is_hidden' => false,
        ]);
        $product->setProductPrices(new Collection([$price]));

        $rule = new ProductQuestionRule(new Collection, new Collection([$product]));

        return Validator::make(
            ['products' => [$attendee]],
            ['products' => $rule],
        );
    }
}
