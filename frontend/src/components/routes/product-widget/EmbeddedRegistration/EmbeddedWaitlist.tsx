import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {hasLength, isEmail, useForm} from "@mantine/form";
import {Button, Checkbox, TextInput} from "@mantine/core";
import {IconArrowLeft, IconCheck} from "@tabler/icons-react";
import {Event} from "../../../../types.ts";
import {useJoinWaitlist} from "../../../../mutations/useJoinWaitlist.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {useWaitlistJoined} from "../../../../hooks/useWaitlistJoined.ts";
import {readBuyerProfile, readCheckoutDraft} from "../../../../utilites/checkoutMemory.ts";
import {WaitlistTarget} from "../../../common/JoinWaitlistButton/WaitlistTunnelContext.ts";
import classes from "./EmbeddedRegistration.module.scss";

const fieldClassNames = {
    root: classes.field,
    label: classes.fieldLabel,
    description: classes.fieldDescription,
    input: classes.fieldInput,
    error: classes.fieldError,
    required: classes.fieldRequired,
};

const consentClassNames = {
    root: classes.consent,
    body: classes.consentBody,
    label: classes.consentLabel,
    input: classes.consentInput,
    error: classes.fieldError,
};

interface WaitlistValues {
    first_name: string;
    email: string;
    phone: string;
    last_name: string;
    consent: boolean;
}

interface EmbeddedWaitlistProps {
    event: Event;
    target: WaitlistTarget;
    onBack: () => void;
}

export const EmbeddedWaitlist = ({event, target, onBack}: EmbeddedWaitlistProps) => {
    const mutation = useJoinWaitlist();
    const errorHandler = useFormErrorResponseHandler();
    const {markJoined} = useWaitlistJoined(event.id, target.productPriceId);
    const [done, setDone] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const stepRef = useRef<HTMLDivElement>(null);
    const smsAvailable = !!event.sms_available;
    const productName = target.priceLabel ? `${target.product.title} (${target.priceLabel})` : target.product.title;

    const form = useForm<WaitlistValues>({
        initialValues: {first_name: '', email: '', phone: '', last_name: '', consent: false},
        validate: {
            first_name: hasLength({min: 1}, t`First name is required`),
            email: isEmail(t`Please enter a valid email address`),
            phone: (value) => (smsAvailable && !value?.trim() ? t`Phone number is required` : null),
            consent: (value) => (!value ? t`You must agree to receive messages` : null),
        },
    });

    useEffect(() => {
        const draft = readCheckoutDraft(event.id)?.order;
        const profile = readBuyerProfile();
        const pick = (...values: (string | undefined)[]) => values.find(value => value?.trim()) || '';
        form.setValues({
            first_name: pick(draft?.first_name, profile?.first_name),
            last_name: pick(draft?.last_name, profile?.last_name),
            email: pick(draft?.email, profile?.email),
            phone: pick(profile?.phone),
        });
    }, [event.id]);

    useEffect(() => {
        stepRef.current?.scrollIntoView?.({block: 'nearest'});
        stepRef.current?.focus({preventScroll: true});
    }, [done]);

    const submit = ({consent: _, ...values}: WaitlistValues) => {
        setFailure(null);
        mutation.mutate({
            eventId: event.id,
            data: {...values, product_price_id: Number(target.productPriceId)},
        }, {
            onSuccess: () => {
                markJoined();
                setDone(true);
            },
            onError: (error: any) => {
                const message = error?.response?.data?.message;
                if (error?.response?.data?.errors) {
                    errorHandler(form, error);
                } else {
                    setFailure(message || t`Something went wrong. Please try again later.`);
                }
            },
        });
    };

    if (done) {
        return (
            <div className={classes.step} ref={stepRef} tabIndex={-1} role="status">
                <h2 className={classes.stepTitle}>{t`Noted, you are on the waiting list.`}</h2>
                <p className={classes.stepHelp}>
                    {smsAvailable
                        ? t`I will let you know by SMS as soon as a place frees up.`
                        : t`I will let you know by email as soon as a place frees up.`}
                </p>
                <div className={classes.stepFooter}>
                    <button type="button" className={classes.backButton} onClick={onBack}>
                        <IconArrowLeft size={16} aria-hidden="true"/>
                        {t`Back to tickets`}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <form noValidate className={classes.form} onSubmit={form.onSubmit(submit)}>
            <div className={classes.step} ref={stepRef} tabIndex={-1} role="group" aria-labelledby="embedded-waitlist-title">
                <h2 className={classes.stepTitle} id="embedded-waitlist-title">{t`Waiting list: ${productName}`}</h2>
                <p className={classes.stepHelp}>
                    {smsAvailable
                        ? t`This ticket is sold out. Leave your details and I will text you as soon as a place frees up.`
                        : t`This ticket is sold out. Leave your details and I will email you as soon as a place frees up.`}
                </p>
                <div className={classes.fieldRow}>
                    <TextInput
                        {...form.getInputProps('first_name')}
                        label={t`First Name`}
                        withAsterisk
                        name="given-name"
                        autoComplete="given-name"
                        autoCapitalize="words"
                        classNames={fieldClassNames}
                    />
                    <TextInput
                        {...form.getInputProps('email')}
                        type="email"
                        label={t`Email Address`}
                        withAsterisk
                        name="email"
                        autoComplete="email"
                        inputMode="email"
                        autoCapitalize="off"
                        autoCorrect="off"
                        spellCheck={false}
                        classNames={fieldClassNames}
                    />
                </div>
                <div className={classes.fieldRow}>
                    <TextInput
                        {...form.getInputProps('phone')}
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        name="tel"
                        label={t`Phone number`}
                        placeholder="0639 12 34 56"
                        withAsterisk={smsAvailable}
                        classNames={fieldClassNames}
                    />
                    <TextInput
                        {...form.getInputProps('last_name')}
                        label={t`Last name (optional)`}
                        name="family-name"
                        autoComplete="family-name"
                        autoCapitalize="words"
                        classNames={fieldClassNames}
                    />
                </div>
                <div className={classes.consents}>
                    <Checkbox
                        {...form.getInputProps('consent', {type: 'checkbox'})}
                        color="var(--event-primary-color)"
                        iconColor="var(--event-accent-contrast)"
                        classNames={consentClassNames}
                        label={(
                            <>
                                {smsAvailable
                                    ? t`I agree to receive email and SMS notifications related to this event`
                                    : t`I agree to receive email notifications related to this event`}
                                <span className={classes.fieldRequired} aria-hidden="true"> *</span>
                            </>
                        )}
                    />
                </div>
                {!!failure && (
                    <div className={classes.stepError} role="alert"><span>{failure}</span></div>
                )}
                <div className={classes.stepFooter}>
                    <button type="button" className={classes.backButton} onClick={onBack} disabled={mutation.isPending}>
                        <IconArrowLeft size={16} aria-hidden="true"/>
                        {t`Previous`}
                    </button>
                </div>
                <div className={classes.nav} data-step-kind="waitlist">
                    <Button
                        type="submit"
                        className={classes.nextButton}
                        loading={mutation.isPending}
                        rightSection={<IconCheck size={20}/>}
                    >
                        {t`Join Waitlist`}
                    </Button>
                </div>
            </div>
        </form>
    );
};
