import {useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Button, Group, NumberInput, Select, TextInput} from "@mantine/core";
import {NavLink} from "react-router";
import {useForm} from "@mantine/form";
import {
    IconCash,
    IconChevronLeft,
    IconCreditCard,
    IconDeviceMobile,
    IconMinus,
    IconPlus,
    IconPrinter,
    IconReceipt2,
} from "@tabler/icons-react";
import {PageBody} from "../../../../common/PageBody";
import {PageTitle} from "../../../../common/PageTitle";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {useCreateBoxOfficeSale} from "../../../../../mutations/useCreateBoxOfficeSale.ts";
import {useReprintBoxOfficeTicket} from "../../../../../mutations/useReprintBoxOfficeTicket.ts";
import {BoxOfficePaymentMethod, boxOfficeClient, BoxOfficeSale} from "../../../../../api/box-office.client.ts";
import {IdParam} from "../../../../../types.ts";
import {KioskPrintOutput} from "../../../../../hooks/useKioskSettings.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useIsCurrentUserAdmin} from "../../../../../hooks/useIsCurrentUserAdmin.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {availableLocales, getClientLocale, getLocaleName, SupportedLocales} from "../../../../../locales.ts";
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
    defaultLocale?: SupportedLocales | '';
    sendConfirmationEmail?: boolean;
}

const generateIdempotencyKey = (): string => {
    if (typeof window === 'undefined' || !window.crypto?.randomUUID) {
        return '';
    }
    return window.crypto.randomUUID();
};

const hasSaleIdentifier = (values: Pick<SaleFormValues, 'first_name' | 'email' | 'phone'>): boolean => {
    const digits = values.phone.replace(/\D/g, '');
    return values.first_name.trim().length > 0
        || values.email.trim().length > 0
        || digits.length >= 8;
};

const openPdfBlobInNewTab = (blob: Blob) => {
    const blobUrl = URL.createObjectURL(blob);
    const printWindow = window.open(blobUrl, '_blank');
    printWindow?.print();
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
    defaultLocale = '',
    sendConfirmationEmail = false,
}: SaleFormProps) => {
    const errorHandler = useFormErrorResponseHandler();
    const isAdmin = useIsCurrentUserAdmin();
    const createSale = useCreateBoxOfficeSale();
    const reprintTicket = useReprintBoxOfficeTicket();

    const [idempotencyKey, setIdempotencyKey] = useState('');
    const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
    const [selectedProductPriceId, setSelectedProductPriceId] = useState<number | null>(null);
    const [lastSale, setLastSale] = useState<BoxOfficeSale | null>(null);
    const [step, setStep] = useState<SaleStep>('tarifs');
    const [basket, setBasket] = useState<BasketLine[]>([]);
    const [cartOpen, setCartOpen] = useState(false);
    const [isCheckingOut, setIsCheckingOut] = useState(false);

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
    const selectedPrice: SaleFormPrice | null = selectedProduct?.prices?.find(
        (price) => price.id === selectedProductPriceId
    ) ?? null;
    const selectedPriceRemaining = selectedPrice ? remainingStock(selectedPrice) : null;

    const selectProduct = (product: SaleFormProduct) => {
        setSelectedProductId(product.id ?? null);
        const prices = product.prices ?? [];
        const singlePrice = prices.length === 1 ? prices[0] : null;
        setSelectedProductPriceId(singlePrice?.id ?? null);
        form.setFieldValue('amount_collected', singlePrice?.price ?? '');
    };

    const selectPrice = (price: SaleFormPrice) => {
        setSelectedProductPriceId(price.id ?? null);
        form.setFieldValue('amount_collected', price.price);
    };

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
        setBasket([]);
        setCartOpen(false);
    };

    const handleTicketTap = (product: SaleFormProduct) => {
        const prices = product.prices ?? [];
        if (prices.length === 1) {
            addToBasket(product, prices[0]);
            return;
        }
        setSelectedProductId(product.id ?? null);
        setSelectedProductPriceId(null);
    };

    const basketCount = basket.reduce((sum, line) => sum + line.qty, 0);
    const basketTotal = basket.reduce((sum, line) => sum + line.unitPrice * line.qty, 0);
    const lineQty = (productId?: number, productPriceId?: number) => {
        if (!productId || !productPriceId) {
            return 0;
        }
        return basket.find((line) => line.productId === productId && line.productPriceId === productPriceId)?.qty ?? 0;
    };
    const productQty = (product: SaleFormProduct) =>
        (product.prices ?? []).reduce((sum, price) => sum + lineQty(product.id, price.id), 0);

    useEffect(() => {
        if (variant !== 'kiosk') {
            return;
        }
        form.setFieldValue('amount_collected', basketTotal > 0 ? Number(basketTotal.toFixed(2)) : '');
    }, [basketTotal, variant]);

    const resetForNextSale = () => {
        setSelectedProductId(null);
        setSelectedProductPriceId(null);
        setBasket([]);
        setCartOpen(false);
        setStep('tarifs');
        form.reset();
        form.setFieldValue('locale', (defaultLocale || getClientLocale()) as SupportedLocales);
        setIdempotencyKey(generateIdempotencyKey());
    };

    const printOnZebra = async (attendeePublicId: string) => {
        const host = zebraPrinterHost.trim();
        if (!host) {
            showError(t`Set the Zebra printer IP in Settings before printing.`);
            return;
        }

        await boxOfficeClient.printZpl(eventId, attendeePublicId, host);
    };

    const openTicketPdf = async (attendeePublicId: string) => {
        try {
            const pdf = await boxOfficeClient.getTicketPdf(eventId, attendeePublicId);
            openPdfBlobInNewTab(pdf);
        } catch {
            showError(t`Could not open the ticket PDF. Use the reprint button to try again.`);
        }
    };

    const printTicket = async (attendeePublicId: string, isReprint = false) => {
        if (printMode === 'none' || skipPrint) {
            return;
        }

        if (printMode === 'zebra') {
            try {
                await printOnZebra(attendeePublicId);
            } catch {
                showError(isReprint
                    ? t`The Zebra printer did not respond.`
                    : t`The ticket was created but the Zebra printer did not respond.`);
            }
            return;
        }

        if (isReprint) {
            reprintTicket.mutate({eventId, attendeePublicId}, {
                onSuccess: (pdf) => openPdfBlobInNewTab(pdf),
                onError: () => showError(t`Could not reprint the ticket. Please try again.`),
            });
            return;
        }

        await openTicketPdf(attendeePublicId);
    };

    const handleReprint = (attendeePublicId: string) => {
        void printTicket(attendeePublicId, true);
    };

    const checkoutBasket = async (values: SaleFormValues) => {
        if (basketCount === 0 || values.amount_collected === '') {
            return;
        }

        setIsCheckingOut(true);

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
                    first_name: values.first_name || undefined,
                    last_name: values.last_name || undefined,
                    email: values.email || undefined,
                    locale: values.locale,
                    amount: Number(basketTotal.toFixed(2)),
                    payment_method: values.payment_method,
                    amount_collected: Number(values.amount_collected),
                    idempotency_key: generateIdempotencyKey() || idempotencyKey,
                    send_confirmation_email: sendConfirmationEmail && values.email.trim().length > 0,
                },
            });

            const sale = response.data;
            const soldAttendees = sale.attendees?.length ? sale.attendees : [sale.attendee];
            setLastSale(sale);

            if (!skipPrint) {
                for (const attendee of soldAttendees) {
                    await printTicket(attendee.public_id);
                }
            }

            showSuccess(soldAttendees.length === 1
                ? t`Ticket created — ${sale.attendee.public_id}`
                : t`${soldAttendees.length} tickets created`);
            resetForNextSale();
        } catch (error: any) {
            const status = error?.response?.status;
            if (status === 422) {
                const priceError = error.response?.data?.errors?.amount;
                showError(priceError || t`The price has changed — please reselect the ticket type.`);
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
        if (variant === 'kiosk') {
            void checkoutBasket(values);
            return;
        }

        if (!selectedProduct?.id || !selectedPrice?.id || values.amount_collected === '') {
            return;
        }

        createSale.mutate({
            eventId,
            sale: {
                product_id: selectedProduct.id,
                product_price_id: selectedPrice.id,
                phone: values.phone || undefined,
                first_name: values.first_name || undefined,
                last_name: values.last_name || undefined,
                email: values.email || undefined,
                locale: values.locale,
                amount: selectedPrice.price,
                payment_method: values.payment_method,
                amount_collected: Number(values.amount_collected),
                idempotency_key: idempotencyKey,
            },
        }, {
            onSuccess: (response) => {
                setLastSale(response.data);
                showSuccess(t`Ticket created — ${response.data.attendee.public_id}`);
                if (!skipPrint) {
                    void printTicket(response.data.attendee.public_id);
                }
                resetForNextSale();
            },
            onError: (error: any) => {
                const status = error?.response?.status;

                if (status === 422) {
                    const priceError = error.response?.data?.errors?.amount;
                    showError(priceError || t`The price has changed — please reselect the ticket type.`);
                    return;
                }

                if (status === 409) {
                    showError(error.response?.data?.message || t`This sale could not be completed. Please try again.`);
                    return;
                }

                errorHandler(form, error);
            },
        });
    };

    const isSubmitDisabled = variant === 'kiosk'
        ? (isCheckingOut || basketCount === 0 || !hasSaleIdentifier(form.values))
        : (createSale.isPending
            || !selectedProduct
            || !selectedPrice
            || !hasSaleIdentifier(form.values)
            || (form.values.payment_method === BoxOfficePaymentMethod.Free && selectedPrice.price > 0));

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
        const continueLabel = step === 'paiement'
            ? (isCheckingOut ? t`Processing…` : t`Finish`)
            : t`Continue`;

        return (
            <div className={kiosk.saleShell}>
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
                        <div className={`${kiosk.step} ${step === 'paiement' ? kiosk.stepActive : ''}`}>{t`Payment`}</div>
                    </div>
                </header>

                <div className={kiosk.saleBody}>
                    <TableSkeleton isVisible={isLoading}/>

                    {!isLoading && step === 'tarifs' && (
                        <>
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
                                placeholder="06 12 34 56 78"
                                type="tel"
                                inputMode="tel"
                                size="lg"
                                mt="md"
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
                            {lastSale && (
                                <div className={kiosk.lastSale}>
                                    <p>
                                        <Trans>Last ticket: <strong>{lastSale.attendee.public_id}</strong></Trans>
                                    </p>
                                    <Button
                                        variant="light"
                                        leftSection={<IconPrinter/>}
                                        loading={reprintTicket.isPending}
                                        onClick={() => handleReprint(lastSale.attendee.public_id)}
                                    >
                                        {t`Reprint`}
                                    </Button>
                                </div>
                            )}
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
                                <button type="button" className={kiosk.cartSecondary} onClick={clearBasket}>
                                    {t`Clear basket`}
                                </button>
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
                    <button
                        type="button"
                        className={`${kiosk.continueButton} ${footerReady ? kiosk.continueButtonReady : ''}`}
                        disabled={!footerReady || isCheckingOut}
                        onClick={handleKioskContinue}
                    >
                        {continueLabel}
                    </button>
                </footer>
            </div>
        );
    }

    return (
        <PageBody>
            <PageTitle subheading={t`Sell a ticket at the door and print it immediately.`}>
                {t`Box Office`}
            </PageTitle>

            {isAdmin && (
                <Group justify="flex-end" mb="md">
                    <Button
                        component={NavLink}
                        to="operators"
                        variant="light"
                    >
                        {t`Manage kiosk operators`}
                    </Button>
                </Group>
            )}

            <TableSkeleton isVisible={isLoading}/>

            {!isLoading && (
                <div className={classes.layout}>
                    <section className={classes.productSection}>
                        <h2 className={classes.sectionTitle}>{t`1. Choose a ticket`}</h2>

                        {products.length === 0 && (
                            <p className={classes.emptyState}>
                                <Trans>
                                    No ticket is both active and attached to an active check-in list.
                                    Attach a product to a check-in list first — otherwise it would not
                                    be scannable at the door.
                                </Trans>
                            </p>
                        )}

                        <div className={classes.productGrid}>
                            {products.map((product) => (
                                <button
                                    key={product.id}
                                    type="button"
                                    className={`${classes.productButton} ${selectedProductId === product.id ? classes.productButtonSelected : ''}`}
                                    onClick={() => selectProduct(product)}
                                >
                                    <span className={classes.productButtonTitle}>{product.title}</span>
                                    {(product.prices?.length ?? 0) === 1 && (
                                        <span className={classes.productButtonPrice}>
                                            {formatCurrency(product.prices![0].price, currency)}
                                        </span>
                                    )}
                                    {(product.prices?.length ?? 0) > 1 && (
                                        <span className={classes.productButtonPrice}>{t`Multiple prices`}</span>
                                    )}
                                </button>
                            ))}
                        </div>

                        {selectedProduct && (selectedProduct.prices?.length ?? 0) > 1 && (
                            <>
                                <h3 className={classes.sectionSubtitle}>{t`Choose a price`}</h3>
                                <div className={classes.priceGrid}>
                                    {selectedProduct.prices!.map((price) => (
                                        <button
                                            key={price.id}
                                            type="button"
                                            className={`${classes.priceButton} ${selectedProductPriceId === price.id ? classes.priceButtonSelected : ''}`}
                                            onClick={() => selectPrice(price)}
                                        >
                                            <span>{price.label || t`Standard`}</span>
                                            <span>{formatCurrency(price.price, currency)}</span>
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}

                        {selectedPrice && (
                            <div className={classes.priceSummary}>
                                <span className={classes.priceSummaryAmount}>
                                    {formatCurrency(selectedPrice.price, currency)}
                                </span>
                                <span className={classes.priceSummaryStock}>
                                    {selectedPriceRemaining !== null
                                        ? t`${selectedPriceRemaining} remaining`
                                        : t`Unlimited`}
                                </span>
                            </div>
                        )}
                    </section>

                    <section className={classes.formSection}>
                        <form onSubmit={form.onSubmit(handleSubmit)}>
                            <h2 className={classes.sectionTitle}>{t`2. Attendee details`}</h2>

                            <TextInput
                                label={t`First name`}
                                {...form.getInputProps('first_name')}
                                mb="sm"
                            />
                            <TextInput
                                label={t`Last name`}
                                {...form.getInputProps('last_name')}
                                mb="sm"
                            />
                            <TextInput
                                label={t`Phone number`}
                                type="tel"
                                inputMode="tel"
                                {...form.getInputProps('phone')}
                                mb="sm"
                            />
                            <TextInput
                                label={t`Email`}
                                type="email"
                                {...form.getInputProps('email')}
                                mb="sm"
                            />
                            <Select
                                label={t`Ticket language`}
                                data={availableLocales.map((locale) => ({
                                    value: locale,
                                    label: getLocaleName(locale as SupportedLocales),
                                }))}
                                {...form.getInputProps('locale')}
                                mb="md"
                            />

                            <h2 className={classes.sectionTitle}>{t`3. Payment method`}</h2>
                            <div className={classes.paymentGrid}>
                                {[
                                    {value: BoxOfficePaymentMethod.Cash, label: t`Cash`},
                                    {value: BoxOfficePaymentMethod.Card, label: t`Card`},
                                    {value: BoxOfficePaymentMethod.Free, label: t`Free`},
                                ].map((option) => {
                                    const disabled = option.value === BoxOfficePaymentMethod.Free
                                        && !!selectedPrice && selectedPrice.price > 0;

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            disabled={disabled}
                                            title={disabled ? t`Free can only be used for a ticket priced at 0` : undefined}
                                            className={`${classes.paymentButton} ${form.values.payment_method === option.value ? classes.paymentButtonSelected : ''}`}
                                            onClick={() => form.setFieldValue('payment_method', option.value)}
                                        >
                                            {option.label}
                                        </button>
                                    );
                                })}
                            </div>

                            <NumberInput
                                label={t`Amount collected`}
                                required
                                min={0}
                                decimalScale={2}
                                fixedDecimalScale
                                {...form.getInputProps('amount_collected')}
                                mt="md"
                                mb="md"
                            />

                            <Button
                                type="submit"
                                size="lg"
                                fullWidth
                                leftSection={<IconReceipt2/>}
                                disabled={isSubmitDisabled}
                            >
                                {createSale.isPending ? t`Processing…` : t`Take Payment & Create Ticket`}
                            </Button>
                        </form>

                        {lastSale && (
                            <div className={classes.lastSale}>
                                <p>
                                    <Trans>Last ticket: <strong>{lastSale.attendee.public_id}</strong></Trans>
                                </p>
                                <Button
                                    variant="light"
                                    leftSection={<IconPrinter/>}
                                    loading={reprintTicket.isPending}
                                    onClick={() => handleReprint(lastSale.attendee.public_id)}
                                >
                                    {t`Reprint`}
                                </Button>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </PageBody>
    );
};
