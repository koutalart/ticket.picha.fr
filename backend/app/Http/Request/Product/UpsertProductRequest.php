<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Product;

use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\RequiredQuantityMode;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertProductRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|max:255',
            'initial_quantity_available' => 'integer|nullable',
            'quantity_sold' => 'integer|default:0',
            'sale_start_date' => 'date|nullable',
            'sale_end_date' => 'date|nullable|after:sale_start_date',
            'max_per_order' => 'integer|nullable',
            'prices' => ['required', 'array'],
            'prices.*.price' => [...RulesHelper::MONEY, 'required'],
            'prices.*.label' => ['nullable', ...RulesHelper::STRING, 'required_if:type,' . ProductPriceType::TIERED->name],
            'prices.*.sale_start_date' => ['date', 'nullable', 'after:sale_start_date'],
            'prices.*.sale_end_date' => 'date|nullable|after:prices.*.sale_start_date',
            'prices.*.initial_quantity_available' => ['integer', 'nullable', 'min:0'],
            'prices.*.is_hidden' => ['boolean'],
            'description' => 'string|nullable',
            'min_per_order' => 'integer|nullable',
            'is_hidden' => 'boolean',
            'hide_before_sale_start_date' => 'boolean',
            'hide_after_sale_end_date' => 'boolean',
            'hide_when_sold_out' => 'boolean',
            'start_collapsed' => 'boolean',
            'show_quantity_remaining' => 'boolean',
            'is_hidden_without_promo_code' => 'boolean',
            'is_visible_at_box_office' => 'boolean',
            'is_sales_paused' => 'boolean',
            'is_required' => 'boolean',
            'is_refund_protection' => 'boolean',
            'required_quantity_mode' => ['nullable', Rule::in(RequiredQuantityMode::valuesArray())],
            'type' => ['required', Rule::in(ProductPriceType::valuesArray())],
            'product_type' => ['required', Rule::in(ProductType::valuesArray())],
            'tax_and_fee_ids' => 'array',
            'product_category_id' => ['required', 'integer'],
            'is_highlighted' => 'boolean',
            'highlight_message' => 'string|nullable|max:255',
            'waitlist_enabled' => 'boolean|nullable',
            'unlock_after_product_id' => 'integer|nullable',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->boolean('is_required')
                || $this->input('required_quantity_mode') === RequiredQuantityMode::PER_TICKET->name) {
                return;
            }

            $requiredQuantity = max(1, (int) $this->input('min_per_order'));
            $maxPerOrder = $this->input('max_per_order');

            if ($maxPerOrder !== null && $maxPerOrder !== '' && (int) $maxPerOrder < $requiredQuantity) {
                $validator->errors()->add(
                    'max_per_order',
                    __('For a required product, the maximum per order must be at least :quantity.', ['quantity' => $requiredQuantity]),
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'sale_end_date.after' => __('The sale end date must be after the sale start date.'),
            'prices.*.sale_end_date.after' => __('The sale end date must be after the sale start date.'),
            'prices.*.sale_end_date.date' => __('The sale end date must be a valid date.'),
            'prices.*.sale_start_date.after' => __('The sale start date must be after the product sale start date.'),
            'product_category_id.required' => __('You must select a product category.'),
        ];
    }
}
