import {useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Button, NumberInput, Select, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {IconPrinter, IconReceipt2} from "@tabler/icons-react";
import {PageBody} from "../../../../common/PageBody";
import {PageTitle} from "../../../../common/PageTitle";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {useCreateBoxOfficeSale} from "../../../../../mutations/useCreateBoxOfficeSale.ts";
import {useReprintBoxOfficeTicket} from "../../../../../mutations/useReprintBoxOfficeTicket.ts";
import {BoxOfficePaymentMethod, boxOfficeClient, BoxOfficeSale} from "../../../../../api/box-office.client.ts";
import {IdParam} from "../../../../../types.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../hooks/useFormErrorResponseHandler.tsx";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {availableLocales, getClientLocale, getLocaleName, SupportedLocales} from "../../../../../locales.ts";
import classes from "../BoxOffice.module.scss";

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
    first_name: string;
    last_name: string;
    email: string;
    locale: SupportedLocales;
    payment_method: BoxOfficePaymentMethod;
    amount_collected: number | '';
}

interface SaleFormProps {
    eventId: IdParam;
    currency?: string;
    products: SaleFormProduct[];
    isLoading: boolean;
}

const generateIdempotencyKey = (): string => {
    if (typeof window === 'undefined' || !window.crypto?.randomUUID) {
        return '';
    }
    return window.crypto.randomUUID();
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

export const SaleForm = ({eventId, currency, products, isLoading}: SaleFormProps) => {
    const errorHandler = useFormErrorResponseHandler();
    const createSale = useCreateBoxOfficeSale();
    const reprintTicket = useReprintBoxOfficeTicket();

    const [idempotencyKey, setIdempotencyKey] = useState('');
    const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
    const [selectedProductPriceId, setSelectedProductPriceId] = useState<number | null>(null);
    const [lastSale, setLastSale] = useState<BoxOfficeSale | null>(null);

    useEffect(() => {
        setIdempotencyKey(generateIdempotencyKey());
    }, []);

    const form = useForm<SaleFormValues>({
        initialValues: {
            first_name: '',
            last_name: '',
            email: '',
            locale: getClientLocale() as SupportedLocales,
            payment_method: BoxOfficePaymentMethod.Cash,
            amount_collected: '',
        },
        validate: {
            first_name: (value) => value.trim().length === 0 ? t`First name is required` : null,
            email: (value) => /^\S+@\S+\.\S+$/.test(value) ? null : t`A valid email is required`,
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

    const resetForNextSale = () => {
        setSelectedProductId(null);
        setSelectedProductPriceId(null);
        form.reset();
        form.setFieldValue('locale', getClientLocale() as SupportedLocales);
        setIdempotencyKey(generateIdempotencyKey());
    };

    const openTicketPdf = async (attendeePublicId: string) => {
        try {
            const pdf = await boxOfficeClient.getTicketPdf(eventId, attendeePublicId);
            openPdfBlobInNewTab(pdf);
        } catch {
            showError(t`Could not open the ticket PDF. Use the reprint button to try again.`);
        }
    };

    const handleReprint = (attendeePublicId: string) => {
        reprintTicket.mutate({eventId, attendeePublicId}, {
            onSuccess: (pdf) => openPdfBlobInNewTab(pdf),
            onError: () => showError(t`Could not reprint the ticket. Please try again.`),
        });
    };

    const handleSubmit = (values: SaleFormValues) => {
        if (!selectedProduct?.id || !selectedPrice?.id || values.amount_collected === '') {
            return;
        }

        createSale.mutate({
            eventId,
            sale: {
                product_id: selectedProduct.id,
                product_price_id: selectedPrice.id,
                first_name: values.first_name,
                last_name: values.last_name || undefined,
                email: values.email,
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
                openTicketPdf(response.data.attendee.public_id);
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

    const isSubmitDisabled = createSale.isPending
        || !selectedProduct
        || !selectedPrice
        || (form.values.payment_method === BoxOfficePaymentMethod.Free && selectedPrice.price > 0);

    return (
        <PageBody>
            <PageTitle subheading={t`Sell a ticket at the door and print it immediately.`}>
                {t`Box Office`}
            </PageTitle>

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
                                required
                                {...form.getInputProps('first_name')}
                                mb="sm"
                            />
                            <TextInput
                                label={t`Last name`}
                                {...form.getInputProps('last_name')}
                                mb="sm"
                            />
                            <TextInput
                                label={t`Email`}
                                type="email"
                                required
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
