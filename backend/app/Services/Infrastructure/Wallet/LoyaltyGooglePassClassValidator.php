<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Wallet;

use Spatie\LaravelMobilePass\Builders\Google\Validators\LoyaltyClassValidator;

class LoyaltyGooglePassClassValidator extends LoyaltyClassValidator
{
    protected function rules(): array
    {
        return parent::rules() + [
            'merchantLocations' => ['nullable', 'array', 'max:10'],
            'merchantLocations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'merchantLocations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
