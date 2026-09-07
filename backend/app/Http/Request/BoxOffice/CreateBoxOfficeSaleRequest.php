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
            'product_id' => ['required_without:items', 'int'],
            'product_price_id' => ['required_without:items', 'int'],
            'items' => ['required_without:product_id', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'int'],
            'items.*.product_price_id' => ['required', 'int'],
            'items.*.quantity' => ['required', 'int', 'min:1', 'max:50'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email'],
            'locale' => ['required', Rule::in(Locale::getSupportedLocales())],
            'amount' => ['required', ...RulesHelper::MONEY],
            'payment_method' => ['required', Rule::in(BoxOfficePaymentMethod::valuesArray())],
            'amount_collected' => ['required', ...RulesHelper::MONEY],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'send_confirmation_email' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasIdentifier = filled(trim((string) $this->input('first_name')))
                || filled(trim((string) $this->input('email')))
                || filled(trim((string) $this->input('phone')));

            if (! $hasIdentifier) {
                $validator->errors()->add(
                    'email',
                    __('Saisir au moins un nom, un e-mail ou un téléphone.'),
                );
            }

            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }

            $total = 0;
            foreach ($items as $item) {
                $total += (int) ($item['quantity'] ?? 0);
            }

            if ($total > 50) {
                $validator->errors()->add('items', __('A sale cannot contain more than 50 tickets.'));
            }
        });
    }
}
