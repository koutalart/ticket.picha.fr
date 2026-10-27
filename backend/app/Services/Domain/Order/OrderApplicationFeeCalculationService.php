<?php

namespace HiEvents\Services\Domain\Order;

use Brick\Money\Currency;
use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountVatSettingDomainObject;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Order\DTO\ApplicationFeeValuesDTO;
use HiEvents\Services\Domain\Order\Vat\VatRateDeterminationService;
use HiEvents\Services\Infrastructure\CurrencyConversion\CurrencyConversionClientInterface;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;

class OrderApplicationFeeCalculationService
{
    public function __construct(
        private readonly Repository                        $config,
        private readonly CurrencyConversionClientInterface $currencyConversionClient,
        private readonly VatRateDeterminationService       $vatRateDeterminationService,
        private readonly ProductRepositoryInterface        $productRepository,
    )
    {
    }

    public function calculateApplicationFee(
        AccountConfigurationDomainObject $accountConfiguration,
        OrderDomainObject                $order,
        ?AccountVatSettingDomainObject   $vatSettings = null
    ): ?ApplicationFeeValuesDTO
    {
        $currency = $order->getCurrency();

        if (!$this->config->get('app.saas_mode_enabled')) {
            return null;
        }

        $chargeableItems = $this->getChargeableItems($order);
        $quantityPurchased = $this->getChargeableQuantityPurchased($chargeableItems);
        $chargeableTotal = $this->getChargeableTotal($order, $chargeableItems);

        $fixedFee = $this->getConvertedFixedFee($accountConfiguration, $currency);
        $percentageFee = $accountConfiguration->getPercentageApplicationFee();

        $netApplicationFee = MoneyValue::fromFloat(
            amount: ($fixedFee->toFloat() * $quantityPurchased) + ($chargeableTotal * $percentageFee / 100),
            currency: $currency
        );

        if (!$vatSettings) {
            return new ApplicationFeeValuesDTO(
                grossApplicationFee: $netApplicationFee,
                netApplicationFee: $netApplicationFee,
            );
        }

        return $this->calculateFeeWithVat(
            vatSettings: $vatSettings,
            netApplicationFee: $netApplicationFee,
            currency: $currency,
        );
    }

    private function getConvertedFixedFee(
        AccountConfigurationDomainObject $accountConfiguration,
        string                           $currency
    ): MoneyValue
    {
        $baseCurrency = $accountConfiguration->getApplicationFeeCurrency();

        if ($currency === $baseCurrency) {
            return MoneyValue::fromFloat($accountConfiguration->getFixedApplicationFee(), $currency);
        }

        return $this->currencyConversionClient->convert(
            fromCurrency: Currency::of($baseCurrency),
            toCurrency: Currency::of($currency),
            amount: $accountConfiguration->getFixedApplicationFee()
        );
    }

    /**
     * Required products (is_required) and the cancellation protection (is_refund_protection) never carry a platform fee.
     *
     * @return OrderItemDomainObject[]
     */
    private function getChargeableItems(OrderDomainObject $order): array
    {
        $items = collect($order->getOrderItems() ?? []);
        $requiredProductIds = $this->getExemptProductIds($items->all());

        return $items
            ->reject(fn(OrderItemDomainObject $item) => in_array($item->getProductId(), $requiredProductIds, true))
            ->values()
            ->all();
    }

    /**
     * @param OrderItemDomainObject[] $items
     * @return int[]
     */
    private function getExemptProductIds(array $items): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map(static fn(OrderItemDomainObject $item) => $item->getProductId(), $items)
        )));

        if ($productIds === []) {
            return [];
        }

        return $this->productRepository
            ->findWhereIn(ProductDomainObjectAbstract::ID, $productIds)
            ->filter(fn(ProductDomainObject $product) => OrderPlatformFeePassThroughService::isExemptProduct($product))
            ->map(fn(ProductDomainObject $product) => $product->getId())
            ->values()
            ->all();
    }

    /**
     * @param OrderItemDomainObject[] $chargeableItems
     */
    private function getChargeableTotal(OrderDomainObject $order, array $chargeableItems): float
    {
        $excludedTotal = collect($order->getOrderItems() ?? [])
            ->reject(fn(OrderItemDomainObject $item) => in_array($item, $chargeableItems, true))
            ->sum(fn(OrderItemDomainObject $item) => (float)$item->getTotalGross());

        return max(0.0, (float)$order->getTotalGross() - $excludedTotal);
    }

    /**
     * @param OrderItemDomainObject[] $chargeableItems
     */
    private function getChargeableQuantityPurchased(array $chargeableItems): int
    {
        $quantityPurchased = 0;
        foreach ($chargeableItems as $item) {
            if ($item->getPrice() > 0) {
                $quantityPurchased += $item->getQuantity();
            }
        }

        return $quantityPurchased;
    }

    /**
     * Calculate application fee with VAT added on top.
     *
     * Note: This uses a VAT-exclusive approach where VAT is calculated on top of the
     * net application fee and added to reach the gross amount charged to the customer.
     * For example, with a 12% application fee on a £5.00 order and 20% VAT:
     * - Net application fee: £0.60 (12% of order)
     * - VAT: £0.12 (20% of £0.60)
     * - Gross charged: £0.72 (£0.60 + £0.12)
     */
    private function calculateFeeWithVat(
        AccountVatSettingDomainObject $vatSettings,
        MoneyValue                    $netApplicationFee,
        string                        $currency,
    ): ApplicationFeeValuesDTO
    {
        $vatRate = $this->vatRateDeterminationService->determineVatRatePercentage($vatSettings);

        if ($vatRate <= 0) {
            return new ApplicationFeeValuesDTO(
                grossApplicationFee: $netApplicationFee,
                netApplicationFee: $netApplicationFee,
                applicationFeeVatRate: $vatRate,
            );
        }

        $vatAmount = MoneyValue::fromFloat(
            amount: $netApplicationFee->toFloat() * ($vatRate),
            currency: $currency
        );

        $grossApplicationFee = MoneyValue::fromFloat(
            amount: $netApplicationFee->toFloat() + $vatAmount->toFloat(),
            currency: $currency
        );

        return new ApplicationFeeValuesDTO(
            grossApplicationFee: $grossApplicationFee,
            netApplicationFee: $netApplicationFee,
            applicationFeeVatRate: $vatRate,
            applicationFeeVatAmount: $vatAmount,
        );
    }
}
