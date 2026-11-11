import {useMemo, useState} from "react";
import {Event, GenericModalProps, IdParam, JoinWaitlistRequest, Product} from "../../../types.ts";
import {hasLength, isEmail, useForm} from "@mantine/form";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {useJoinWaitlist} from "../../../mutations/useJoinWaitlist.ts";
import {t} from "@lingui/macro";
import {Button, Checkbox, Modal as MantineModal, Text, TextInput} from "@mantine/core";
import {InputGroup} from "../../common/InputGroup";
import {CheckoutThemeProvider} from "../../layouts/Checkout/CheckoutThemeProvider.tsx";
import {detectMode} from "../../../utilites/themeUtils.ts";
import {BouncingEmoji} from "../../common/BouncingEmoji";

const DEFAULT_ACCENT = '#8b5cf6';

interface JoinWaitlistModalProps extends GenericModalProps {
    product: Product;
    event: Event;
    productPriceId: IdParam;
    priceLabel?: string;
    onSuccess: () => void;
}

export const JoinWaitlistModal = ({onClose, product, event, productPriceId, priceLabel, onSuccess}: JoinWaitlistModalProps) => {
    const errorHandler = useFormErrorResponseHandler();
    const mutation = useJoinWaitlist();
    const [status, setStatus] = useState<'form' | 'success' | 'error'>('form');
    const [errorMessage, setErrorMessage] = useState('');

    const productDisplayName = priceLabel ? `${product?.title} - ${priceLabel}` : product?.title;

    const homepageSettings = event?.settings?.homepage_theme_settings;
    const accentColor = homepageSettings?.accent || DEFAULT_ACCENT;
    const mode = useMemo(
        () => homepageSettings?.mode || detectMode(homepageSettings?.background || '#ffffff'),
        [homepageSettings?.mode, homepageSettings?.background]
    );

    const smsAvailable = !!event?.sms_available;

    const form = useForm<Omit<JoinWaitlistRequest, 'product_price_id'> & { consent: boolean }>({
        initialValues: {
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            consent: false,
        },
        validate: {
            first_name: hasLength({min: 1}, t`First name is required`),
            email: isEmail(t`Please enter a valid email address`),
            phone: (value) => (smsAvailable && !value?.trim() ? t`Phone number is required` : null),
            consent: (value) => (!value ? t`You must agree to receive messages` : null),
        },
        validateInputOnBlur: true,
    });

    const handleSubmit = ({consent: _, ...values}: Omit<JoinWaitlistRequest, 'product_price_id'> & { consent: boolean }) => {
        mutation.mutate({
            eventId: event.id,
            data: {
                ...values,
                product_price_id: Number(productPriceId),
            },
        }, {
            onSuccess: () => {
                setStatus('success');
                form.reset();
            },
            onError: (error: any) => {
                const message = error?.response?.data?.message;
                if (message) {
                    setErrorMessage(message);
                    setStatus('error');
                } else {
                    errorHandler(form, error);
                }
            },
        });
    };

    const handleClose = () => {
        if (status === 'success') {
            onSuccess();
        }
        onClose();
    };

    if (status === 'success') {
        return (
            <CheckoutThemeProvider accentColor={accentColor} mode={mode}>
                <MantineModal
                    opened
                    onClose={handleClose}
                    size="lg"
                    overlayProps={{opacity: 0.55, blur: 3}}
                    withCloseButton={false}
                >
                    <div style={{textAlign: 'center', padding: '30px 20px'}}>
                        <BouncingEmoji emoji="🎉"/>
                        <Text size="xl" fw={600} mb="xs">
                            {t`You're on the waitlist!`}
                        </Text>
                        <Text size="sm" c="dimmed" mb="xl">
                            {smsAvailable
                                ? t`We'll notify you by email and SMS if a spot becomes available for ${productDisplayName}.`
                                : t`We'll notify you by email if a spot becomes available for ${productDisplayName}.`}
                        </Text>
                        <Button fullWidth onClick={handleClose}>
                            {t`Close`}
                        </Button>
                    </div>
                </MantineModal>
            </CheckoutThemeProvider>
        );
    }

    if (status === 'error') {
        return (
            <CheckoutThemeProvider accentColor={accentColor} mode={mode}>
                <MantineModal
                    opened
                    onClose={handleClose}
                    size="lg"
                    overlayProps={{opacity: 0.55, blur: 3}}
                    withCloseButton={false}
                >
                    <div style={{textAlign: 'center', padding: '30px 20px'}}>
                        <BouncingEmoji emoji="😕"/>
                        <Text size="xl" fw={600} mb="xs">
                            {t`Unable to join waitlist`}
                        </Text>
                        <Text size="sm" c="dimmed" mb="xl">
                            {errorMessage || t`Something went wrong. Please try again later.`}
                        </Text>
                        <Button fullWidth onClick={handleClose}>
                            {t`Close`}
                        </Button>
                    </div>
                </MantineModal>
            </CheckoutThemeProvider>
        );
    }

    return (
        <CheckoutThemeProvider accentColor={accentColor} mode={mode}>
            <MantineModal
                opened
                onClose={onClose}
                title={t`Join Waitlist for ${productDisplayName}`}
                size="lg"
                overlayProps={{opacity: 0.55, blur: 3}}
                closeOnClickOutside={false}
                styles={{
                    title: {fontWeight: 600, fontSize: '1.25rem'},
                }}
            >
                <form
                    onSubmit={(e) => {
                        e.stopPropagation();
                        form.onSubmit(handleSubmit)(e);
                    }}
                    style={{padding: '0 15px 15px'}}
                >
                    <InputGroup>
                        <TextInput
                            {...form.getInputProps('first_name')}
                            label={t`First Name`}
                            required
                        />
                        <TextInput
                            {...form.getInputProps('last_name')}
                            label={t`Last Name`}
                        />
                    </InputGroup>
                    <TextInput
                        {...form.getInputProps('email')}
                        label={t`Email`}
                        type="email"
                        required
                    />
                    <TextInput
                        {...form.getInputProps('phone')}
                        label={t`Phone number`}
                        type="tel"
                        autoComplete="tel"
                        required={smsAvailable}
                    />
                    <Checkbox
                        {...form.getInputProps('consent', {type: 'checkbox'})}
                        label={smsAvailable
                            ? t`I agree to receive email and SMS notifications related to this event`
                            : t`I agree to receive email notifications related to this event`}
                        mt="md"
                        error={form.errors.consent}
                    />
                    <Button
                        type="submit"
                        fullWidth
                        mt="xl"
                        disabled={mutation.isPending}
                    >
                        {mutation.isPending
                            ? t`Working...`
                            : t`Join Waitlist`
                        }
                    </Button>
                </form>
            </MantineModal>
        </CheckoutThemeProvider>
    );
};
