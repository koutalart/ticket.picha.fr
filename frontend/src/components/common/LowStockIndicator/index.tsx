import {t} from "@lingui/macro";
import {ProductPrice} from "../../../types.ts";
import {formatCompactCurrency, getDisplayUnitPrice} from "../../../utilites/eventConversion.ts";
import classes from "./LowStockIndicator.module.scss";

interface LowStockIndicatorProps {
    price: ProductPrice;
    currency?: string;
    priceDisplayMode?: string;
    unitPrice?: number;
    className?: string;
}

/**
 * Real remaining stock of a limited price, sent by the server only once it runs low.
 */
export const LowStockIndicator = ({price, currency, priceDisplayMode, unitPrice: shownUnitPrice, className}: LowStockIndicatorProps) => {
    const lowStock = price.low_stock;
    if (!lowStock || lowStock.remaining <= 0 || price.is_sold_out || price.is_available === false) {
        return null;
    }

    const remaining = lowStock.remaining;
    const unitPrice = shownUnitPrice ?? getDisplayUnitPrice(price, priceDisplayMode);
    const amount = formatCompactCurrency(unitPrice, currency);
    const label = unitPrice > 0
        ? (remaining === 1 ? t`Only 1 place left at ${amount}` : t`Only ${remaining} places left at ${amount}`)
        : (remaining === 1 ? t`Only 1 place left` : t`Only ${remaining} places left`);
    const percent = Math.min(100, Math.max(2, lowStock.percent_remaining));

    return (
        <div className={className ? `${classes.lowStock} ${className}` : classes.lowStock}>
            <span className={classes.label}>{label}</span>
            <span className={classes.track} aria-hidden="true">
                <span className={classes.fill} style={{width: `${percent}%`}}/>
            </span>
        </div>
    );
};
