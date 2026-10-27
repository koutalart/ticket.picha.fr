import React from 'react';
import {formatCurrency} from "../../../utilites/currency.ts";
import {Product, ProductPrice} from "../../../types.ts";
import {t} from "@lingui/macro";

interface CurrencyProps {
    price?: number | null | undefined;
    currency?: string;
    strikeThrough?: boolean;
    className?: string;
    freeLabel?: string | null;
}

export const Currency: React.FC<CurrencyProps> = ({
                                                      price,
                                                      currency = 'USD',
                                                      strikeThrough,
                                                      className,
                                                      freeLabel,
                                                  }) => {
    if (!price) {
        return freeLabel ? freeLabel : formatCurrency(0, currency);
    }

    let formattedPrice = <>{formatCurrency(price, currency)}</>;

    if (strikeThrough) {
        formattedPrice = <s>{formattedPrice}</s>;
    }

    return (
        <span className={className}>
            {formattedPrice}
        </span>
    );
};

interface ProductPriceProps {
    product: Product;
    price: ProductPrice;
    currency?: string;
    className?: string;
    freeLabel?: string | null;
    taxAndServiceFeeDisplayType?: 'INCLUSIVE' | 'EXCLUSIVE';
}

/**
 * fee_total comes from the backend and already contains the platform fee paid by the buyer,
 * computed from the account configuration (see ProductFilterService::processProductPrice).
 */
export const ProductPriceDisplay: React.FC<ProductPriceProps> = ({
                                                                   price,
                                                                   currency = 'USD',
                                                                   className,
                                                                   freeLabel,
                                                                   taxAndServiceFeeDisplayType = 'EXCLUSIVE',
                                                               }) => {
    const basePrice = Number(price.price) || 0;
    const taxTotal = Number(price.tax_total) || 0;
    const feeTotal = Number(price.fee_total) || 0;
    const totalTaxAndFees = taxTotal + feeTotal;
    const isInclusive = taxAndServiceFeeDisplayType === 'INCLUSIVE';

    if (basePrice === 0 && totalTaxAndFees === 0) {
        return <span className={className}>{freeLabel || t`Free`}</span>;
    }

    const displayPrice = isInclusive ? basePrice + totalTaxAndFees : basePrice;

    const getFeeNote = (): string | null => {
        if (totalTaxAndFees === 0) {
            return null;
        }

        if (isInclusive) {
            return taxTotal > 0 ? t`Taxes and fees included` : t`Service fee included`;
        }

        const formattedTotal = formatCurrency(totalTaxAndFees, currency);

        if (taxTotal > 0 && feeTotal > 0) {
            return t`+ ${formattedTotal} taxes and fees`;
        }

        if (taxTotal > 0) {
            return t`+ ${formattedTotal} taxes`;
        }

        return t`+ ${formattedTotal} service fee`;
    };

    const feeNote = getFeeNote();

    return (
        <div className={className}>
            <div>
                <div>{formatCurrency(displayPrice, currency)}</div>
                {feeNote && <div className={'hi-price-fee-note'}>{feeNote}</div>}
            </div>
        </div>
    );
};
