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
}

export const RefundProtectionOption = ({offer, checked, disabled = false, onChange}: RefundProtectionOptionProps) => {
    const isDisabled = disabled || offer.is_locked;
    const price = formatCurrency(offer.total_before_additions, offer.currency);
    const extraFees = Math.round((Number(offer.total_fee) + Number(offer.total_tax)) * 100) / 100;
    const formattedFees = formatCurrency(extraFees, offer.currency);
    const unitPrice = offer.quantity > 0
        ? formatCurrency(Number(offer.total_before_additions) / offer.quantity, offer.currency)
        : price;

    return (
        <label className={classNames(classes.option, {
            [classes.checked]: checked,
            [classes.disabled]: isDisabled,
        })}>
            <IconShieldCheck size={22} className={classes.icon}/>
            <div className={classes.body}>
                <div className={classes.label}>
                    {offer.is_locked
                        ? t`Peace of mind option included: ${price}`
                        : t`Add the peace of mind option for ${price}`}
                </div>
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
                    <div className={classes.details}>{t`Included in every order for this event.`}</div>
                )}
            </div>
            <Checkbox
                className={classes.checkbox}
                checked={checked}
                disabled={isDisabled}
                onChange={(event) => onChange(event.currentTarget.checked)}
                aria-label={t`Add the peace of mind option for ${price}`}
            />
        </label>
    );
};
