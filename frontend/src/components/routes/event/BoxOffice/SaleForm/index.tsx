import {useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {ActionIcon, Button, NumberInput, Select, Skeleton, TextInput} from "@mantine/core";
import {NavLink} from "react-router";
import {useForm} from "@mantine/form";
import {
    IconCash,
    IconChevronLeft,
    IconCreditCard,
    IconDeviceMobile,
    IconMaximize,
    IconMinus,
    IconPlayerPause,
    IconPlus,
    IconPrinter,
    IconTrash,
    IconUsers,
} from "@tabler/icons-react";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {useCreateBoxOfficeSale} from "../../../../../mutations/useCreateBoxOfficeSale.ts";
import {useKioskTicketPrinter} from "../../../../../hooks/useKioskTicketPrinter.tsx";
import {BoxOfficePaymentMethod, BoxOfficeSale} from "../../../../../api/box-office.client.ts";
import {IdParam} from "../../../../../types.ts";
import {KioskPrintOutput, ZebraLabelFormat} from "../../../../../hooks/useKioskSettings.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../utilites/confirmationDialog.tsx";
import {useFormErrorResponseHandler} from "../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useIsCurrentUserAdmin} from "../../../../../hooks/useIsCurrentUserAdmin.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {availableLocales, getClientLocale, SupportedLocales} from "../../../../../locales.ts";
import {getLocaleName} from "../../../../../utilites/localeNames.ts";
import classes from "../BoxOffice.module.scss";
import kiosk from "../../../../layouts/Kiosk/Kiosk.module.scss";

export interface SaleFormPrice {
    id?: number;
    label?: string | null;
    price: number;
    quantity_remaining?: number | null;
    initial_quantity_available?: number | null;
    quantity_sold?: number;
}

export interface SaleFormProduct {
    id?: number;
    title: string;
    prices?: SaleFormPrice[];
}

interface SaleFormValues {
    phone: string;
    first_name: string;
    last_name: string;
    email: string;
    locale: SupportedLocales;
    payment_method: BoxOfficePaymentMethod;
    amount_collected: number | '';
}

type SaleStep = 'tarifs' | 'formulaire' | 'paiement';

interface BasketLine {
    productId: number;
    productPriceId: number;
    title: string;
    priceLabel: string | null;
    unitPrice: number;
    qty: number;
    remaining: number | null;
}

const lineKey = (productId: number, productPriceId: number) => `${productId}:${productPriceId}`;

interface SaleFormProps {
    eventId: IdParam;
    currency?: string;
    products: SaleFormProduct[];
    isLoading: boolean;
    variant?: 'manage' | 'kiosk';
    eventTitle?: string;
    skipPrint?: boolean;
    printMode?: KioskPrintOutput | 'a4';
    zebraPrinterHost?: string;
    zebraLabelFormat?: ZebraLabelFormat;
    defaultLocale?: SupportedLocales | '';
    sendConfirmationEmail?: boolean;
    phoneCallingCode?: string;
    onZebraPrinterHostChange?: (host: string) => void;
    pausedCount?: number;
}


const generateIdempotencyKey = (): string => {
    if (typeof window === 'undefined' || !window.crypto?.randomUUID) {
        return '';
    }
    return window.crypto.randomUUID();
};

const hasBadgeName = (values: Pick<SaleFormValues, 'first_name' | 'last_name'>): boolean =>
    values.first_name.trim().length > 0 && values.last_name.trim().length > 0;

const hasSaleIdentifier = (values: Pick<SaleFormValues, 'first_name' | 'email' | 'phone'>): boolean => {
    const digits = values.phone.replace(/\D/g, '');
    return values.first_name.trim().length > 0
        || values.email.trim().length > 0
        || digits.length >= 8;
};


const remainingStock = (price: SaleFormPrice): number | null => {
    if (price.quantity_remaining !== undefined) {
        return price.quantity_remaining;
    }
    if (price.initial_quantity_available === undefined || price.initial_quantity_available === null) {
        return null;
    }
    return Math.max(0, price.initial_quantity_available - (price.quantity_sold ?? 0));
};

export const SaleForm = ({
    eventId,
    currency,
    products,
    isLoading,
    variant = 'manage',
    eventTitle,
    skipPrint = false,
    printMode = 'a4',
    zebraPrinterHost = '',
    zebraLabelFormat,
    defaultLocale = '',
    sendConfirmationEmail = false,
    phoneCallingCode = '',
    onZebraPrinterHostChange,
    pausedCount = 0,
}: SaleFormProps) => {
    const errorHandler = useFormErrorResponseHandler();
    const isAdmin = useIsCurrentUserAdmin();
    const createSale = useCreateBoxOfficeSale();
    const callingCodeDigits = phoneCallingCode.replace(/\D/g, '');
    const {printTickets, printerPromptModal, isReprinting} = useKioskTicketPrinter({
        eventId,
        printMode,
        skipPrint,
        zebraPrinterHost,
        zebraLabelFormat,
        onZebraPrinterHostChange,
    });

    const [idempotencyKey, setIdempotencyKey] = useState('');
    const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
    const [lastSale, setLastSale] = useState<BoxOfficeSale | null>(null);
    const [step, setStep] = useState<SaleStep>('tarifs');
    const [basket, setBasket] = useState<BasketLine[]>([]);
    const [cartOpen, setCartOpen] = useState(false);
    const [isCheckingOut, setIsCheckingOut] = useState(false);
    const [lastSaleAttendeeIds, setLastSaleAttendeeIds] = useState<string[]>([]);
    const [cashReceived, setCashReceived] = useState<number | ''>('');

    useEffect(() => {
        setIdempotencyKey(generateIdempotencyKey());
    }, []);

    const form = useForm<SaleFormValues>({
        initialValues: {
            phone: '',
            first_name: '',
            last_name: '',
            email: '',
            locale: (defaultLocale || getClientLocale()) as SupportedLocales,
            payment_method: BoxOfficePaymentMethod.Cash,
            amount_collected: '',
        },
        validate: {
            phone: (value) => {
                const digits = value.replace(/\D/g, '');
                if (digits.length === 0) {
                    return null;
                }
                if (!callingCodeDigits && !value.trim().startsWith('+')) {
                    return t`Missing phone calling code — set it in station settings.`;
                }
                return digits.length >= 8 ? null : t`A valid phone number is required`;
            },
            first_name: () => null,
            email: (value) => {
                if (value.trim().length === 0) {
                    return null;
                }
                return /^\S+@\S+\.\S+$/.test(value)
                    ? null
                    : t`A valid email is required`;
            },
            amount_collected: (value) => (value === '' || value === null) ? t`Amount collected is required` : null,
        },
    });

    const selectedProduct = products.find((product) => product.id === selectedProductId) ?? null;



    const addToBasket = (product: SaleFormProduct, price: SaleFormPrice) => {
        if (!product.id || !price.id) {
            return;
        }
        const remaining = remainingStock(price);
        const key = lineKey(product.id, price.id);
        const existing = basket.find((line) => lineKey(line.productId, line.productPriceId) === key);
        const nextQty = (existing?.qty ?? 0) + 1;
        if (remaining !== null && nextQty > remaining) {
            showError(t`No more tickets available for this price.`);
            return;
        }
        setBasket((current) => {
            const currentLine = current.find((line) => lineKey(line.productId, line.productPriceId) === key);
            if (!currentLine) {
                return [
                    ...current,
                    {
                        productId: product.id!,
                        productPriceId: price.id!,
                        title: product.title,
                        priceLabel: price.label ?? null,
                        unitPrice: price.price,
                        qty: 1,
                        remaining,
                    },
                ];
            }
            return current.map((line) =>
                lineKey(line.productId, line.productPriceId) === key
                    ? {...line, qty: line.qty + 1}
                    : line
            );
        });
    };

    const removeFromBasket = (productId: number, productPriceId: number) => {
        const key = lineKey(productId, productPriceId);
        setBasket((current) => current
            .map((line) => lineKey(line.productId, line.productPriceId) === key
                ? {...line, qty: line.qty - 1}
                : line)
            .filter((line) => line.qty > 0)
        );
    };

    const clearBasket = () => {
        confirmationDialog(
            t`Empty the basket? All selected tickets and the customer details will be removed.`,
            () => resetForNextSale(),
            {confirm: t`Empty basket`, cancel: t`Keep`},
        );
    };

    const handleTicketTap = (product: SaleFormProduct) => {
        const prices = product.prices ?? [];
        if (prices.length === 1) {
            addToBasket(product, prices[0]);
            return;
        }
        setSelectedProductId(product.id ?? null);
    };

    const basketCount = basket.reduce((sum, line) => sum + line.qty, 0);
    const basketTotal = basket.reduce((sum, line) => sum + line.unitPrice * line.qty, 0);
    const isFreeCart = basketCount > 0 && basketTotal === 0;
    const lineQty = (productId?: number, productPriceId?: number) => {
        if (!productId || !productPriceId) {
            return 0;
        }
        return basket.find((line) => line.productId === productId && line.productPriceId === productPriceId)?.qty ?? 0;
    };
    const productQty = (product: SaleFormProduct) =>
        (product.prices ?? []).reduce((sum, price) => sum + lineQty(product.id, price.id), 0);

    useEffect(() => {
        form.setFieldValue('amount_collected', basketCount > 0 ? Number(basketTotal.toFixed(2)) : '');
    }, [basketTotal, basketCount]);

    const resetForNextSale = () => {
        setSelectedProductId(null);
        setBasket([]);
        setCartOpen(false);
        setStep('tarifs');
        setCashReceived('');
        form.reset();
        form.setFieldValue('locale', (defaultLocale || getClientLocale()) as SupportedLocales);
        setIdempotencyKey(generateIdempotencyKey());
    };

    const handleReprint = (attendeePublicIds: string[]) => {
        void printTickets(attendeePublicIds, true);
    };

    const checkoutBasket = async (values: SaleFormValues) => {
        if (basketCount === 0 || (values.amount_collected === '' && !isFreeCart)) {
            return;
        }

        setIsCheckingOut(true);

        const saleIdempotencyKey = idempotencyKey || generateIdempotencyKey();
        if (!idempotencyKey) {
            setIdempotencyKey(saleIdempotencyKey);
        }

        try {
            const response = await createSale.mutateAsync({
                eventId,
                sale: {
                    items: basket.map((line) => ({
                        product_id: line.productId,
                        product_price_id: line.productPriceId,
                        quantity: line.qty,
                    })),
                    phone: values.phone || undefined,
                    phone_calling_code: callingCodeDigits || undefined,
                    first_name: values.first_name || undefined,
                    last_name: values.last_name || undefined,
                    email: values.email || undefined,
                    locale: values.locale,
                    amount: Number(basketTotal.toFixed(2)),
                    payment_method: isFreeCart ? BoxOfficePaymentMethod.Free : values.payment_method,
                    amount_collected: isFreeCart ? 0 : Number(values.amount_collected),
                    idempotency_key: saleIdempotencyKey,
                    send_confirmation_email: sendConfirmationEmail && values.email.trim().length > 0,
                },
            });

            const sale = response.data;
            const soldAttendees = sale.attendees?.length ? sale.attendees : [sale.attendee];
            const soldAttendeeIds = soldAttendees.map((attendee) => attendee.public_id);
            setLastSale(sale);
            setLastSaleAttendeeIds(soldAttendeeIds);

            showSuccess(soldAttendees.length === 1
                ? t`Ticket created — ${sale.attendee.public_id}`
                : t`${soldAttendees.length} tickets created`);
            resetForNextSale();

            await printTickets(soldAttendeeIds);
        } catch (error: any) {
            const status = error?.response?.status;
            if (status === 422) {
                const priceError = error.response?.data?.errors?.amount;
                const callingError = error.response?.data?.errors?.phone_calling_code;
                const callingMessage = Array.isArray(callingError) ? callingError[0] : callingError;
                const eventError = error.response?.data?.errors?.event_id;
                const eventMessage = Array.isArray(eventError) ? eventError[0] : eventError;
                showError(eventMessage || callingMessage || priceError || t`The price has changed — please reselect the ticket type.`);
            } else if (status === 409) {
                showError(error.response?.data?.message || t`This sale could not be completed. Please try again.`);
            } else {
                errorHandler(form, error);
            }
        } finally {
            setIsCheckingOut(false);
        }
    };

    const handleSubmit = (values: SaleFormValues) => {
        void checkoutBasket(values);
    };

    const isSubmitDisabled = variant === 'kiosk'
        ? (isCheckingOut || basketCount === 0 || (isFreeCart ? !hasBadgeName(form.values) : !hasSaleIdentifier(form.values)))
        : (isCheckingOut || basketCount === 0 || !hasSaleIdentifier(form.values));

    const canContinueFromTarifs = basketCount > 0;
    const footerReady = (step === 'tarifs' && canContinueFromTarifs)
        || step === 'formulaire'
        || (step === 'paiement' && !isSubmitDisabled);

    const handleKioskContinue = () => {
        if (step === 'tarifs' && canContinueFromTarifs) {
            setCartOpen(false);
            setStep('formulaire');
            return;
        }

        if (step === 'formulaire') {
            const phone = form.validateField('phone');
            const email = form.validateField('email');
            if (phone.hasError || email.hasError) {
                return;
            }
            if (isFreeCart) {
                if (!form.values.first_name.trim()) {
                    form.setFieldError('first_name', t`First and last name are required to print the badge.`);
                }
                if (!form.values.last_name.trim()) {
                    form.setFieldError('last_name', t`First and last name are required to print the badge.`);
                }
                if (!hasBadgeName(form.values)) {
                    return;
                }
                if (!isSubmitDisabled) {
                    form.onSubmit(handleSubmit)();
                }
                return;
            }
            if (!hasSaleIdentifier(form.values)) {
                form.setFieldError('email', t`Enter at least a first name, an email, or a phone number.`);
                return;
            }
            setStep('paiement');
            return;
        }

        if (step === 'paiement' && !isSubmitDisabled) {
            form.onSubmit(handleSubmit)();
        }
    };

    const handleBack = () => {
        if (step === 'formulaire') {
            setStep('tarifs');
        } else if (step === 'paiement') {
            setStep('formulaire');
        }
    };

    if (variant === 'kiosk') {
        const continueLabel = (step === 'paiement' || (step === 'formulaire' && isFreeCart))
            ? (isCheckingOut ? t`Processing…` : t`Finish`)
            : t`Continue`;

        return (
            <div className={kiosk.saleShell}>
                {printerPromptModal}
                <header className={kiosk.saleHeader}>
                    <h1 className={kiosk.saleEvent}>
                        {step !== 'tarifs' && (
                            <button type="button" className={kiosk.backButton} onClick={handleBack} aria-label={t`Back`}>
                                <IconChevronLeft size={22}/>
                            </button>
                        )}
                        {eventTitle ?? t`Box Office`}
                    </h1>
                    <div className={kiosk.steps}>
                        <div className={`${kiosk.step} ${step === 'tarifs' ? kiosk.stepActive : ''}`}>{t`Rates`}</div>
                        <div className={`${kiosk.step} ${step === 'formulaire' ? kiosk.stepActive : ''}`}>{t`Form`}</div>
                        {!isFreeCart && (
                            <div className={`${kiosk.step} ${step === 'paiement' ? kiosk.stepActive : ''}`}>{t`Payment`}</div>
                        )}
                    </div>
                </header>

                <div className={kiosk.saleBody}>
                    <TableSkeleton isVisible={isLoading}/>

                    {!isLoading && step === 'tarifs' && (
                        <>
                            {lastSale && lastSaleAttendeeIds.length > 0 && (
                                <div className={kiosk.lastSale}>
                                    <p>
                                        {lastSaleAttendeeIds.length === 1
                                            ? <Trans>Last ticket: <strong>{lastSaleAttendeeIds[0]}</strong></Trans>
                                            : <Trans>Last sale: <strong>{lastSaleAttendeeIds.length} tickets</strong></Trans>}
                                    </p>
                                    <Button
                                        variant="light"
                                        leftSection={<IconPrinter/>}
                                        loading={isReprinting}
                                        onClick={() => handleReprint(lastSaleAttendeeIds)}
                                    >
                                        {t`Reprint`}
                                    </Button>
                                </div>
                            )}
                            <p className={kiosk.categoryLabel}>{t`Tickets`}</p>
                            {products.length === 0 && (
                                <p className={kiosk.emptyState}>
                                    <Trans>
                                        No ticket is both active and attached to an active check-in list.
                                        Attach a product to a check-in list first — otherwise it would not
                                        be scannable at the door.
                                    </Trans>
                                </p>
                            )}
                            <div className={kiosk.ticketGrid}>
                                {products.map((product) => {
                                    const qty = productQty(product);
                                    const singlePrice = (product.prices?.length ?? 0) === 1 ? product.prices![0] : null;
                                    return (
                                        <div
                                            key={product.id}
                                            className={`${kiosk.ticketCard} ${qty > 0 ? kiosk.ticketCardSelected : ''}`}
                                        >
                                            <button
                                                type="button"
                                                className={kiosk.ticketCardHit}
                                                onClick={() => handleTicketTap(product)}
                                            >
                                                <span className={kiosk.ticketTitle}>{product.title}</span>
                                                {singlePrice && (
                                                    <span className={kiosk.ticketPrice}>
                                                        {formatCurrency(singlePrice.price, currency)}
                                                    </span>
                                                )}
                                                {!singlePrice && (
                                                    <span className={kiosk.ticketPrice}>{t`Multiple prices`}</span>
                                                )}
                                            </button>
                                            {qty > 0 && (
                                                <div className={kiosk.ticketQtyBar}>
                                                    <button
                                                        type="button"
                                                        className={kiosk.qtyButton}
                                                        aria-label={t`Remove ticket`}
                                                        onClick={() => singlePrice?.id && product.id
                                                            ? removeFromBasket(product.id, singlePrice.id)
                                                            : undefined}
                                                        disabled={!singlePrice?.id}
                                                    >
                                                        <IconMinus size={18}/>
                                                    </button>
                                                    <span className={kiosk.ticketQty}>{qty}</span>
                                                    <button
                                                        type="button"
                                                        className={kiosk.qtyButton}
                                                        aria-label={t`Add ticket`}
                                                        onClick={() => handleTicketTap(product)}
                                                    >
                                                        <IconPlus size={18}/>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                            {selectedProduct && (selectedProduct.prices?.length ?? 0) > 1 && (
                                <>
                                    <p className={kiosk.categoryLabel}>{t`Choose a price`}</p>
                                    <div className={kiosk.ticketGrid}>
                                        {selectedProduct.prices!.map((price) => {
                                            const qty = lineQty(selectedProduct.id, price.id);
                                            return (
                                                <div
                                                    key={price.id}
                                                    className={`${kiosk.ticketCard} ${qty > 0 ? kiosk.ticketCardSelected : ''}`}
                                                >
                                                    <button
                                                        type="button"
                                                        className={kiosk.ticketCardHit}
                                                        onClick={() => addToBasket(selectedProduct, price)}
                                                    >
                                                        <span className={kiosk.ticketTitle}>{price.label || t`Standard`}</span>
                                                        <span className={kiosk.ticketPrice}>{formatCurrency(price.price, currency)}</span>
                                                    </button>
                                                    {qty > 0 && (
                                                        <div className={kiosk.ticketQtyBar}>
                                                            <button
                                                                type="button"
                                                                className={kiosk.qtyButton}
                                                                aria-label={t`Remove ticket`}
                                                                onClick={() => selectedProduct.id && price.id
                                                                    && removeFromBasket(selectedProduct.id, price.id)}
                                                            >
                                                                <IconMinus size={18}/>
                                                            </button>
                                                            <span className={kiosk.ticketQty}>{qty}</span>
                                                            <button
                                                                type="button"
                                                                className={kiosk.qtyButton}
                                                                aria-label={t`Add ticket`}
                                                                onClick={() => addToBasket(selectedProduct, price)}
                                                            >
                                                                <IconPlus size={18}/>
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </>
                    )}

                    {!isLoading && step === 'formulaire' && (
                        <div className={kiosk.formCard}>
                            <TextInput
                                label={t`First name`}
                                size="lg"
                                {...form.getInputProps('first_name')}
                            />
                            <TextInput
                                label={t`Last name`}
                                size="lg"
                                mt="md"
                                {...form.getInputProps('last_name')}
                            />
                            <TextInput
                                label={t`Phone number`}
                                placeholder={callingCodeDigits ? '639780773' : '06 12 34 56 78'}
                                type="tel"
                                inputMode="tel"
                                size="lg"
                                mt="md"
                                leftSection={callingCodeDigits ? `+${callingCodeDigits}` : undefined}
                                leftSectionPointerEvents="none"
                                description={callingCodeDigits
                                    ? t`Local number without the leading 0.`
                                    : t`Missing phone calling code — set it in station settings.`}
                                {...form.getInputProps('phone')}
                            />
                            <TextInput
                                label={t`Email`}
                                type="email"
                                size="lg"
                                mt="md"
                                {...form.getInputProps('email')}
                            />
                            <Select
                                label={t`Ticket language`}
                                size="lg"
                                mt="md"
                                data={availableLocales.map((locale) => ({
                                    value: locale,
                                    label: getLocaleName(locale as SupportedLocales),
                                }))}
                                {...form.getInputProps('locale')}
                            />
                            <p className={kiosk.emptyState} style={{marginTop: 16}}>
                                {t`Optional in a rush — enter at least a first name, an email, or a phone number. The email can be completed later from the attendees screen.`}
                            </p>
                        </div>
                    )}

                    {!isLoading && step === 'paiement' && (
                        <>
                            <div className={kiosk.paymentGrid}>
                                {[
                                    {value: BoxOfficePaymentMethod.Cash, label: t`Cash`, icon: IconCash},
                                    {value: BoxOfficePaymentMethod.Card, label: t`Card`, icon: IconCreditCard},
                                ].map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        className={`${kiosk.paymentCard} ${form.values.payment_method === option.value ? kiosk.paymentCardSelected : ''}`}
                                        onClick={() => form.setFieldValue('payment_method', option.value)}
                                    >
                                        <option.icon size={28}/>
                                        {option.label}
                                    </button>
                                ))}
                                {[
                                    {id: 'mvola', label: 'MVola'},
                                    {id: 'orange-money', label: 'Orange Money'},
                                ].map((method) => (
                                    <button
                                        key={method.id}
                                        type="button"
                                        disabled
                                        title={t`Coming soon`}
                                        className={kiosk.paymentCard}
                                    >
                                        <IconDeviceMobile size={28}/>
                                        {method.label}
                                        <span className={kiosk.paymentCardComingSoon}>{t`Coming soon`}</span>
                                    </button>
                                ))}
                            </div>
                            <div className={kiosk.formCard} style={{marginTop: 24}}>
                                <NumberInput
                                    label={t`Amount collected`}
                                    required
                                    min={0}
                                    decimalScale={2}
                                    fixedDecimalScale
                                    {...form.getInputProps('amount_collected')}
                                    size="md"
                                />
                            </div>
                        </>
                    )}
                </div>

                {cartOpen && (
                    <div className={kiosk.cartOverlay} onClick={() => setCartOpen(false)}>
                        <div className={kiosk.cartPanel} onClick={(event) => event.stopPropagation()}>
                            <h2 className={kiosk.cartTitle}>{t`Cart contents`}</h2>
                            {basket.length === 0 && (
                                <p className={kiosk.emptyState}>{t`The basket is empty.`}</p>
                            )}
                            {basket.map((line) => (
                                <div key={lineKey(line.productId, line.productPriceId)} className={kiosk.cartRow}>
                                    <div className={kiosk.cartRowInfo}>
                                        <strong>{line.title}</strong>
                                        {line.priceLabel && <span>{line.priceLabel}</span>}
                                        <span>{formatCurrency(line.unitPrice, currency)}</span>
                                    </div>
                                    <div className={kiosk.ticketQtyBar}>
                                        <button
                                            type="button"
                                            className={kiosk.qtyButton}
                                            aria-label={t`Remove ticket`}
                                            onClick={() => removeFromBasket(line.productId, line.productPriceId)}
                                        >
                                            <IconMinus size={18}/>
                                        </button>
                                        <span className={kiosk.ticketQty}>{line.qty}</span>
                                        <button
                                            type="button"
                                            className={kiosk.qtyButton}
                                            aria-label={t`Add ticket`}
                                            onClick={() => {
                                                const product = products.find((item) => item.id === line.productId);
                                                const price = product?.prices?.find((item) => item.id === line.productPriceId);
                                                if (product && price) {
                                                    addToBasket(product, price);
                                                }
                                            }}
                                        >
                                            <IconPlus size={18}/>
                                        </button>
                                    </div>
                                    <div className={kiosk.cartRowTotal}>
                                        {formatCurrency(line.unitPrice * line.qty, currency)}
                                    </div>
                                </div>
                            ))}
                            <div className={kiosk.cartActions}>
                                <button
                                    type="button"
                                    className={kiosk.cartPrimary}
                                    onClick={() => {
                                        setCartOpen(false);
                                        setStep('tarifs');
                                    }}
                                >
                                    {t`Add tickets`}
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                <footer className={`${kiosk.saleFooter} ${footerReady ? kiosk.saleFooterReady : ''}`}>
                    <button
                        type="button"
                        className={kiosk.basketHit}
                        onClick={() => setCartOpen((open) => !open)}
                        aria-label={t`Cart contents`}
                    >
                        <div className={kiosk.basketCount}>{basketCount}</div>
                        <div className={kiosk.basketMeta}>
                            <div className={kiosk.basketLabel}>{t`tickets in the basket`}</div>
                            {basketCount > 0 && (
                                <div className={kiosk.basketDetail}>
                                    {basket.map((line) => `${line.qty}x ${line.title}`).join(', ')}
                                </div>
                            )}
                        </div>
                    </button>
                    {basketCount > 0 && (
                        <div className={kiosk.footerTotal}>
                            {formatCurrency(basketTotal, currency)}
                        </div>
                    )}
                    <div className={kiosk.footerActions}>
                        {basketCount > 0 && (
                            <button
                                type="button"
                                className={kiosk.clearBasketButton}
                                onClick={clearBasket}
                                disabled={isCheckingOut}
                                aria-label={t`Empty basket`}
                                title={t`Empty basket`}
                            >
                                <IconTrash size={24}/>
                            </button>
                        )}
                        <button
                            type="button"
                            className={`${kiosk.continueButton} ${footerReady ? kiosk.continueButtonReady : ''}`}
                            disabled={!footerReady || isCheckingOut}
                            onClick={handleKioskContinue}
                        >
                            {continueLabel}
                        </button>
                    </div>
                </footer>
            </div>
        );
    }

    const cashReceivedValue = typeof cashReceived === 'number' ? cashReceived : null;
    const changeDue = cashReceivedValue !== null ? cashReceivedValue - basketTotal : null;
    const isCash = form.values.payment_method === BoxOfficePaymentMethod.Cash;

    return (
        <div className={classes.page}>
            {printerPromptModal}
            <header className={classes.header}>
                <div>
                    <span className={classes.kicker}>{t`Box Office`}</span>
                    <h1 className={classes.title}>{eventTitle ?? <Skeleton height={36} width={240} radius="sm"/>}</h1>
                </div>
                <div className={classes.headerActions}>
                    {isAdmin && (
                        <Button component={NavLink} to="operators" variant="default" radius="md"
                                leftSection={<IconUsers size={18}/>}>
                            {t`Operators`}
                        </Button>
                    )}
                    <Button component="a" href={`/kiosk/event/${eventId}/sell`} target="_blank" rel="noopener"
                            variant="default" radius="md" leftSection={<IconMaximize size={18}/>}>
                        {t`Full screen`}
                    </Button>
                </div>
            </header>

            {isLoading && <Skeleton height={260} radius="md"/>}

            {!isLoading && (
                <form className={classes.layout} onSubmit={form.onSubmit(handleSubmit)}>
                    <div className={classes.mainColumn}>
                        <section className={classes.card}>
                            <h2 className={classes.cardTitle}>{t`Tickets on sale at the box office`}</h2>

                            {products.length === 0 && (
                                <p className={classes.muted}>
                                    <Trans>
                                        No box office ticket is on sale. Check on the Tickets page that a ticket is sold
                                        at the box office, that its sale is turned on, and that it is on a check-in list.
                                    </Trans>
                                </p>
                            )}

                            <div className={classes.tiles}>
                                {products.flatMap((product) => (product.prices ?? []).map((price) => {
                                    const qty = lineQty(product.id, price.id);
                                    const remaining = remainingStock(price);
                                    const soldOut = remaining !== null && remaining <= 0;
                                    const hasManyPrices = (product.prices?.length ?? 0) > 1;

                                    return (
                                        <div key={`${product.id}-${price.id}`}
                                             className={`${classes.tile} ${qty > 0 ? classes.tileActive : ''} ${soldOut ? classes.tileSoldOut : ''}`}>
                                            <button type="button" className={classes.tileInfo} disabled={soldOut}
                                                    onClick={() => addToBasket(product, price)}>
                                                <span className={classes.tileTitle}>
                                                    {product.title}{hasManyPrices && <> · {price.label || t`Standard`}</>}
                                                </span>
                                                <span className={classes.tileMeta}>
                                                    {formatCurrency(price.price, currency)}
                                                    {' · '}
                                                    {soldOut
                                                        ? t`Sold out`
                                                        : remaining !== null ? t`${remaining} remaining` : t`Unlimited`}
                                                </span>
                                            </button>
                                            <div className={classes.stepper}>
                                                <ActionIcon size={40} radius="md" variant="default" disabled={qty === 0}
                                                            aria-label={t`Remove ticket`}
                                                            onClick={() => product.id && price.id && removeFromBasket(product.id, price.id)}>
                                                    <IconMinus size={18}/>
                                                </ActionIcon>
                                                <span className={classes.stepperValue}>{qty}</span>
                                                <ActionIcon size={40} radius="md" variant="default" disabled={soldOut}
                                                            aria-label={t`Add ticket`}
                                                            onClick={() => addToBasket(product, price)}>
                                                    <IconPlus size={18}/>
                                                </ActionIcon>
                                            </div>
                                        </div>
                                    );
                                }))}
                            </div>

                            {pausedCount > 0 && (
                                <p className={classes.pausedNote}>
                                    <IconPlayerPause size={16}/>
                                    {pausedCount === 1
                                        ? t`1 box office ticket is paused and hidden here.`
                                        : t`${pausedCount} box office tickets are paused and hidden here.`}
                                    {' '}
                                    <NavLink to="../products">{t`Manage tickets`}</NavLink>
                                </p>
                            )}
                        </section>

                        <section className={classes.card}>
                            <h2 className={classes.cardTitle}>{t`Customer`}</h2>
                            <p className={classes.muted}>{t`Enter at least a first name, an email, or a phone number.`}</p>
                            <div className={classes.fieldGrid}>
                                <TextInput label={t`First name`} {...form.getInputProps('first_name')}/>
                                <TextInput label={t`Last name`} {...form.getInputProps('last_name')}/>
                                <TextInput
                                    label={t`Phone number`}
                                    type="tel"
                                    inputMode="tel"
                                    leftSection={callingCodeDigits ? `+${callingCodeDigits}` : undefined}
                                    leftSectionPointerEvents="none"
                                    description={callingCodeDigits
                                        ? t`Local number without the leading 0.`
                                        : t`Start with the country code, e.g. +262 639…`}
                                    {...form.getInputProps('phone')}
                                />
                                <TextInput label={t`Email`} type="email" {...form.getInputProps('email')}/>
                            </div>
                            <Select
                                label={t`Ticket language`}
                                data={availableLocales.map((locale) => ({
                                    value: locale,
                                    label: getLocaleName(locale as SupportedLocales),
                                }))}
                                {...form.getInputProps('locale')}
                                mt="sm"
                                maw={260}
                            />
                        </section>
                    </div>

                    <aside className={classes.checkout}>
                        <h2 className={classes.cardTitle}>{t`To collect`}</h2>

                        {basket.length === 0 && <p className={classes.muted}>{t`Add tickets with the + buttons.`}</p>}

                        {basket.map((line) => (
                            <div key={lineKey(line.productId, line.productPriceId)} className={classes.checkoutLine}>
                                <span>{line.qty} × {line.title}{line.priceLabel ? ` · ${line.priceLabel}` : ''}</span>
                                <span>{formatCurrency(line.unitPrice * line.qty, currency)}</span>
                            </div>
                        ))}

                        <div className={classes.total}>{formatCurrency(basketTotal, currency)}</div>

                        {isFreeCart ? (
                            <p className={classes.muted}>{t`Free tickets: nothing to collect.`}</p>
                        ) : (
                            <>
                                <div className={classes.methods}>
                                    {[
                                        {value: BoxOfficePaymentMethod.Cash, label: t`Cash`, icon: IconCash},
                                        {value: BoxOfficePaymentMethod.Card, label: t`Card`, icon: IconCreditCard},
                                    ].map((option) => (
                                        <button key={option.value} type="button"
                                                className={`${classes.method} ${form.values.payment_method === option.value ? classes.methodActive : ''}`}
                                                onClick={() => form.setFieldValue('payment_method', option.value)}>
                                            <option.icon size={18}/>{option.label}
                                        </button>
                                    ))}
                                </div>

                                {isCash && basketCount > 0 && (
                                    <>
                                        <NumberInput
                                            label={t`Amount received`}
                                            value={cashReceived}
                                            onChange={(value) => setCashReceived(typeof value === 'number' ? value : '')}
                                            min={0}
                                            decimalScale={2}
                                            hideControls
                                            mt="md"
                                        />
                                        {changeDue !== null && (
                                            <p className={changeDue < 0 ? classes.changeMissing : classes.change}>
                                                {changeDue < 0
                                                    ? t`Missing: ${formatCurrency(-changeDue, currency)}`
                                                    : t`Change to give back: ${formatCurrency(changeDue, currency)}`}
                                            </p>
                                        )}
                                    </>
                                )}
                            </>
                        )}

                        <Button type="submit" size="lg" radius="md" fullWidth mt="md"
                                leftSection={<IconPrinter/>}
                                loading={isCheckingOut}
                                disabled={isSubmitDisabled}>
                            {t`Collect ${formatCurrency(basketTotal, currency)}`}
                        </Button>

                        {basketCount > 0 && (
                            <Button variant="subtle" color="gray" fullWidth mt="xs" leftSection={<IconTrash size={16}/>}
                                    onClick={clearBasket}>
                                {t`Empty basket`}
                            </Button>
                        )}

                        {lastSale && (
                            <div className={classes.lastSale}>
                                <span>
                                    <Trans>Last sale: <strong>{lastSale.attendee.public_id}</strong></Trans>
                                </span>
                                <Button variant="light" size="xs" leftSection={<IconPrinter size={16}/>}
                                        loading={isReprinting}
                                        onClick={() => handleReprint(lastSaleAttendeeIds)}>
                                    {t`Reprint`}
                                </Button>
                            </div>
                        )}
                    </aside>
                </form>
            )}
        </div>
    );
};
