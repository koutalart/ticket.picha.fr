import {t} from "@lingui/macro";
import {Checkbox} from "@mantine/core";
import {IconShieldCheck} from "@tabler/icons-react";
import classNames from "classnames";
import {RefundProtectionOffer} from "../../../../../types.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import classes from "./RefundProtectionOption.module.scss";

interface RefundProtectionOptionProps {
    offer: RefundProtectionOffer;
    checked: boolean;
    disabled?: boolean;
    onChange: (enabled: boolean) => void;
    deadlineHours?: number;
}

export const RefundProtectionOption = ({offer, checked, disabled = false, onChange, deadlineHours = 0}: RefundProtectionOptionProps) => {
    const isDisabled = disabled || offer.is_locked;
    const price = formatCurrency(offer.total_before_additions, offer.currency);
    const extraFees = Math.round((Number(offer.total_fee) + Number(offer.total_tax)) * 100) / 100;
    const formattedFees = formatCurrency(extraFees, offer.currency);
    const mandatoryQuantity = Number(offer.mandatory_quantity) || 0;
    const isMixed = mandatoryQuantity > 0 && !offer.is_locked;
    const mandatoryAmount = formatCurrency(Number(offer.mandatory_total_gross) || 0, offer.currency);
    const threshold = offer.mandatory_above !== null && offer.mandatory_above !== undefined
        ? formatCurrency(Number(offer.mandatory_above), offer.currency)
        : null;
    const label = offer.is_locked
        ? t`Peace of mind option included: ${price}`
        : isMixed
            ? t`Add the peace of mind option to your other tickets for ${price}`
            : t`Add the peace of mind option for ${price}`;
    const unitPrice = offer.quantity > 0
        ? formatCurrency(Number(offer.total_before_additions) / offer.quantity, offer.currency)
        : price;

    if (!offer.is_locked && !isMixed) {
        const refundWindow = deadlineHours > 0
            ? t`on request up to ${deadlineHours} h before the event starts.`
            : t`on request until the event starts.`;

        return (
            <label className={classNames(classes.option, classes.highlight, {
                [classes.checked]: checked,
                [classes.disabled]: isDisabled,
                [classes.invite]: !checked && !isDisabled,
            })}>
                <div className={classes.highlightHeader}>
                    <span className={classes.badge}>{t`Recommended`}</span>
                </div>
                <div className={classes.highlightRow}>
                    <IconShieldCheck size={30} className={classes.highlightIcon}/>
                    <div className={classes.body}>
                        <div className={classes.headline}>{t`Can't make it after all? Your ticket is refunded.`}</div>
                        <div className={classes.refundWindow}>{refundWindow}</div>
                        <div className={classes.label}>{label}</div>
                        {offer.is_per_ticket && offer.quantity > 1 && (
                            <div className={classes.details}>{t`${unitPrice} per ticket`}</div>
                        )}
                        {extraFees > 0 && (
                            <div className={classes.details}>{t`+ ${formattedFees} in fees`}</div>
                        )}
                    </div>
                    <Checkbox
                        className={classes.checkbox}
                        size="md"
                        checked={checked}
                        disabled={isDisabled}
                        onChange={(event) => onChange(event.currentTarget.checked)}
                        aria-label={label}
                    />
                </div>
            </label>
        );
    }

    return (
        <label className={classNames(classes.option, {
            [classes.checked]: checked,
            [classes.disabled]: isDisabled,
            [classes.invite]: !checked && !isDisabled,
        })}>
            <IconShieldCheck size={22} className={classes.icon}/>
            <div className={classes.body}>
                {isMixed && (
                    <div className={classes.details}>
                        {threshold
                            ? t`Peace of mind option included for tickets above ${threshold}: ${mandatoryAmount}`
                            : t`Peace of mind option included: ${mandatoryAmount}`}
                    </div>
                )}
                <div className={classes.label}>{label}</div>
                {offer.is_per_ticket && offer.quantity > 1 && (
                    <div className={classes.details}>{t`${unitPrice} per ticket`}</div>
                )}
                {offer.description && (
                    <div className={classes.details}>{offer.description}</div>
                )}
                {extraFees > 0 && (
                    <div className={classes.details}>{t`+ ${formattedFees} in fees`}</div>
                )}
                {offer.is_locked && (
                    <div className={classes.details}>
                        {!offer.is_required_by_organizer && mandatoryQuantity > 0 && threshold
                            ? t`Required for tickets above ${threshold}.`
                            : t`Included in every order for this event.`}
                    </div>
                )}
            </div>
            <Checkbox
                className={classes.checkbox}
                checked={checked}
                disabled={isDisabled}
                onChange={(event) => onChange(event.currentTarget.checked)}
                aria-label={label}
            />
        </label>
    );
};
