<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Wallet;

use Spatie\LaravelMobilePass\Builders\Google\LoyaltyPassBuilder;

class LoyaltyGooglePassBuilder extends LoyaltyPassBuilder
{
    /** @var array<int, array{latitude: float, longitude: float}> */
    protected array $merchantLocations = [];

    public function addMerchantLocation(float $latitude, float $longitude): self
    {
        $this->merchantLocations[] = ['latitude' => $latitude, 'longitude' => $longitude];

        return $this;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->compileGoogleObjectPayload();
    }

    /** @return array<string, mixed> */
    protected function compileData(): array
    {
        return parent::compileData() + $this->filterEmpty([
            'merchantLocations' => $this->merchantLocations,
        ]);
    }
}
