import {UseFormReturnType} from "@mantine/form";
import {Button, NumberInput, TextInput, UnstyledButton} from "@mantine/core";
import {IconCurrencyEuro, IconLockOpen, IconPercentage, IconRefresh, IconTicket} from "@tabler/icons-react";
import {PromoCode, PromoCodeDiscountType} from "../../../types.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useParams} from "react-router";
import {LoadingMask} from "../../common/LoadingMask";
import {t} from "@lingui/macro";
import {InputGroup} from "../../common/InputGroup";
import {getCurrencySymbol} from "../../../utilites/currency.ts";
import {ProductSelector} from "../../common/ProductSelector";
import {DateTimeInput} from "../../common/DateTimeInput";
import {MoreOptions} from "../../common/MoreOptions";
import classes from "./PromoCodeForm.module.scss";

interface PromoCodeFormProps {
    form: UseFormReturnType<PromoCode>,
}

export const PromoCodeForm = ({form}: PromoCodeFormProps) => {
    const {eventId} = useParams();
    const {data: event, data: {product_categories: productCategories} = {}} = useGetEvent(eventId);

    if (!event || !productCategories) {
        return <LoadingMask/>
    }

    const discountType = form.values.discount_type ?? PromoCodeDiscountType.None;
    const isAccessCode = discountType === PromoCodeDiscountType.None;
    const hasLimits = (!isAccessCode && (form.values.applicable_product_ids?.length ?? 0) > 0)
        || !!form.values.expiry_date
        || !!form.values.max_allowed_usages;

    const choices = [
        {
            value: PromoCodeDiscountType.Percentage,
            icon: IconPercentage,
            title: t`Percentage discount`,
            hint: t`e.g. 10% off the order`,
        },
        {
            value: PromoCodeDiscountType.Fixed,
            icon: IconCurrencyEuro,
            title: t`Fixed discount`,
            hint: t`e.g. ${getCurrencySymbol(event.currency)}5 off per ticket`,
        },
        {
            value: PromoCodeDiscountType.None,
            icon: IconLockOpen,
            title: t`Unlock a hidden ticket`,
            hint: t`VIP access, partners`,
        },
    ];

    const generateRandomCode = () => {
        form.setFieldValue('code', Math.random().toString(36).substring(2, 10).toUpperCase());
    };

    const selectType = (value: PromoCodeDiscountType) => {
        form.setFieldValue('discount_type', value);
        if (value === PromoCodeDiscountType.None) {
            form.setFieldValue('discount', undefined);
        }
    };

    const productSelector = (label: string) => (
        <ProductSelector
            label={label}
            placeholder={t`Select tickets`}
            icon={<IconTicket size="1rem"/>}
            productCategories={productCategories}
            form={form}
            productFieldName="applicable_product_ids"
        />
    );

    return (
        <>
            <p className={classes.question}>{t`What should this code do?`}</p>
            <div className={classes.choices}>
                {choices.map((choice) => (
                    <UnstyledButton key={choice.value} type="button"
                                    className={discountType === choice.value ? classes.choiceActive : classes.choice}
                                    onClick={() => selectType(choice.value)}
                                    aria-pressed={discountType === choice.value}>
                        <choice.icon size={20} className={classes.choiceIcon}/>
                        <span>
                            <span className={classes.choiceTitle}>{choice.title}</span>
                            <span className={classes.choiceHint}>{choice.hint}</span>
                        </span>
                    </UnstyledButton>
                ))}
            </div>

            <InputGroup>
                <TextInput
                    {...form.getInputProps('code')}
                    label={t`Code`}
                    placeholder="NOEL10"
                    required
                    rightSection={(
                        <Button variant="subtle" size="xs" color="gray" onClick={generateRandomCode}
                                style={{fontWeight: 400}} title={t`Generate code`}
                                leftSection={<IconRefresh size={16}/>}>
                            {t`Generate`}
                        </Button>
                    )}
                    rightSectionWidth={'auto'}
                />
                {!isAccessCode && (
                    <NumberInput
                        decimalScale={2}
                        min={0}
                        max={discountType === PromoCodeDiscountType.Percentage ? 100 : undefined}
                        rightSection={discountType === PromoCodeDiscountType.Percentage
                            ? <IconPercentage size={16}/>
                            : getCurrencySymbol(event.currency)}
                        {...form.getInputProps('discount')}
                        label={discountType === PromoCodeDiscountType.Percentage ? t`Discount %` : t`Discount in ${event.currency}`}
                        placeholder="10"
                        required
                    />
                )}
            </InputGroup>

            {isAccessCode && productSelector(t`Hidden ticket(s) unlocked by this code`)}

            <MoreOptions key={hasLimits ? 'limited' : 'unlimited'} label={t`Limit (tickets, date, number of uses)`} defaultOpened={hasLimits}>
                {!isAccessCode && productSelector(t`Tickets concerned (all by default)`)}
                <InputGroup>
                    <DateTimeInput {...form.getInputProps('expiry_date')} label={t`Valid until`}/>
                    <NumberInput min={1}
                                 placeholder={t`Unlimited`}
                                 {...form.getInputProps('max_allowed_usages')}
                                 label={t`Maximum number of uses`}/>
                </InputGroup>
            </MoreOptions>
        </>
    );
};
