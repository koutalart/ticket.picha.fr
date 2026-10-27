<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Validators\Rules\ProductQuestionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ProductQuestionRuleEmailConfirmationTest extends TestCase
{
    public function test_attendee_without_email_confirmation_is_accepted(): void
    {
        $validator = $this->validate($this->attendee());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_older_client_sending_matching_email_confirmation_is_accepted(): void
    {
        $validator = $this->validate($this->attendee(['email_confirmation' => 'jo@example.com']));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_older_client_sending_empty_email_confirmation_is_accepted(): void
    {
        $validator = $this->validate($this->attendee(['email_confirmation' => '']));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
    }

    public function test_mismatching_email_confirmation_is_still_rejected(): void
    {
        $validator = $this->validate($this->attendee(['email_confirmation' => 'other@example.com']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('products.0.email_confirmation', $validator->errors()->toArray());
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
        $price = (new ProductPriceDomainObject())->setId(30)->setProductId(20)->setPrice(15);

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

        $rule = new ProductQuestionRule(new Collection(), new Collection([$product]));

        return Validator::make(
            ['products' => [$attendee]],
            ['products' => $rule],
        );
    }
}
