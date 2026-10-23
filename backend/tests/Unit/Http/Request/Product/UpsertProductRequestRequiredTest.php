<?php

namespace Tests\Unit\Http\Request\Product;

use HiEvents\Http\Request\Product\UpsertProductRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpsertProductRequestRequiredTest extends TestCase
{
    public function testRequiredProductNeedsAMaximumAtLeastItsForcedQuantity(): void
    {
        $this->assertTrue($this->fails(['is_required' => true, 'min_per_order' => 2, 'max_per_order' => 1]));
        $this->assertTrue($this->fails(['is_required' => true, 'min_per_order' => null, 'max_per_order' => 0]));
        $this->assertFalse($this->fails(['is_required' => true, 'min_per_order' => 1, 'max_per_order' => 1]));
        $this->assertFalse($this->fails(['is_required' => true, 'min_per_order' => 2, 'max_per_order' => null]));
        $this->assertFalse($this->fails(['is_required' => false, 'min_per_order' => 2, 'max_per_order' => 1]));
    }

    private function fails(array $data): bool
    {
        $request = UpsertProductRequest::create('/', 'POST', $data);
        $validator = Validator::make($data, [
            'is_required' => 'boolean',
            'min_per_order' => 'integer|nullable',
            'max_per_order' => 'integer|nullable',
        ]);
        $request->withValidator($validator);

        return $validator->fails() && $validator->errors()->has('max_per_order');
    }
}
