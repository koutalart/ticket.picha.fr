<?php

namespace HiEvents\Http\Request\BoxOffice;

use HiEvents\DomainObjects\Enums\BoxOfficePaymentMethod;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;

class CreateBoxOfficeSaleRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'int'],
            'product_price_id' => ['required', 'int'],
            'first_name' => ['required', 'string', 'max:40'],
            'last_name' => ['string', 'max:40'],
            'email' => ['required', 'email'],
            'locale' => ['required', Rule::in(Locale::getSupportedLocales())],
            'amount' => ['required', ...RulesHelper::MONEY],
            'payment_method' => ['required', Rule::in(BoxOfficePaymentMethod::valuesArray())],
            'amount_collected' => ['required', ...RulesHelper::MONEY],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
