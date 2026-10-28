import {useEffect, useState} from "react";
import {t} from "@lingui/macro";
import {IconCheck, IconDiscount2} from "@tabler/icons-react";
import {getClientLocale} from "../../../../locales.ts";
import {PromoCodeDiscountType} from "../../../../types.ts";
import {PublicPromoCode} from "../../../../api/promo-code.client.ts";
import classes from "./HighlightedPromo.module.scss";

interface HighlightedPromoProps {
    code: string;
    promo: PublicPromoCode;
    currency: string;
    applied: boolean;
    applying: boolean;
    onApply: () => void;
}

const pad = (value: number) => String(value).padStart(2, '0');

const formatDiscount = (promo: PublicPromoCode, currency: string): string | null => {
    const discount = Number(promo.discount) || 0;
    if (discount <= 0) {
        return null;
    }
    if (promo.discount_type === PromoCodeDiscountType.Percentage) {
        return `-${discount} %`;
    }
    if (promo.discount_type !== PromoCodeDiscountType.Fixed) {
        return null;
    }
    const decimals = Number.isInteger(discount) ? 0 : 2;
    return '-' + new Intl.NumberFormat(getClientLocale(), {
        style: 'currency',
        currency,
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(discount);
};

export const getPromoExpiry = (promo?: PublicPromoCode | null): number | null => {
    if (!promo?.expiry_date) {
        return null;
    }
    const expiry = Date.parse(promo.expiry_date);
    return Number.isFinite(expiry) ? expiry : null;
};

const useNow = () => {
    const [now, setNow] = useState<number | null>(null);
    useEffect(() => {
        setNow(Date.now());
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
};

export const HighlightedPromo = ({code, promo, currency, applied, applying, onApply}: HighlightedPromoProps) => {
    const now = useNow();
    const expiry = getPromoExpiry(promo);
    const amount = formatDiscount(promo, currency);

    if (now === null || !amount || (expiry !== null && expiry <= now)) {
        return null;
    }

    const displayCode = code.toUpperCase();
    let countdown: string | null = null;
    if (expiry !== null) {
        const totalSeconds = Math.max(0, Math.floor((expiry - now) / 1000));
        const days = Math.floor(totalSeconds / 86400);
        const clock = `${pad(Math.floor((totalSeconds % 86400) / 3600))}:${pad(Math.floor((totalSeconds % 3600) / 60))}:${pad(totalSeconds % 60)}`;
        countdown = days > 0 ? t`Ends in ${days} d ${clock}` : t`Ends in ${clock}`;
    }

    return (
        <div className={classes.promo} data-applied={applied} role="region" aria-label={t`Exclusive discount`}>
            <span className={classes.shine} aria-hidden="true"/>
            <span className={classes.confetti} aria-hidden="true">
                {Array.from({length: 8}, (_, index) => <i key={index}/>)}
            </span>
            <IconDiscount2 size={26} className={classes.icon} aria-hidden="true"/>
            <div className={classes.body}>
                <p className={classes.title}>
                    {t`Exclusive discount: ${amount} with code ${displayCode}`}
                </p>
                {countdown && (
                    <p className={classes.countdown}>{countdown}</p>
                )}
            </div>
            {applied ? (
                <span className={classes.applied}>
                    <IconCheck size={16} aria-hidden="true"/>
                    {t`Applied`}
                </span>
            ) : (
                <button type="button" className={classes.apply} onClick={onApply} disabled={applying}>
                    {t`Apply`}
                </button>
            )}
        </div>
    );
};
