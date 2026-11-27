<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Wallet;

use Spatie\LaravelMobilePass\Builders\Google\LoyaltyPassClass;
use Spatie\LaravelMobilePass\Builders\Google\Validators\GooglePassClassValidator;

class LoyaltyGooglePassClass extends LoyaltyPassClass
{
    /** @var array<int, array{latitude: float, longitude: float}> */
    protected array $merchantLocations = [];

    protected static function validator(): GooglePassClassValidator
    {
        return new LoyaltyGooglePassClassValidator;
    }

    public function addMerchantLocation(float $latitude, float $longitude): self
    {
        $this->merchantLocations[] = ['latitude' => $latitude, 'longitude' => $longitude];

        return $this;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return static::validator()->validate(
            $this->compileData() + $this->compileModules() + ['id' => $this->id()]
        );
    }

    /** @return array<string, mixed> */
    protected function compileData(): array
    {
        return parent::compileData() + $this->filterEmpty([
            'merchantLocations' => $this->merchantLocations,
        ]);
    }
}
