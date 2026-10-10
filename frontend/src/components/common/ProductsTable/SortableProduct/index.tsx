import {useState} from 'react';
import {
    IconCopyPlus,
    IconDots,
    IconLock,
    IconPencil,
    IconReceipt,
    IconSend,
    IconSparkles,
    IconTrash,
} from "@tabler/icons-react";
import classes from "../ProductsTable.module.scss";
import classNames from "classnames";
import {Button, Menu, Popover, Progress, Switch, Tooltip} from "@mantine/core";
import {t} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {relativeDate} from "../../../../utilites/dates.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {
    IdParam,
    MessageType,
    Product,
    ProductCategory,
    ProductPrice,
    ProductPriceType,
    ProductType
} from "../../../../types.ts";
import {useDisclosure} from "@mantine/hooks";
import {useDeleteProduct} from "../../../../mutations/useDeleteProduct.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {EditProductModal} from "../../../modals/EditProductModal";
import {SendMessageModal} from "../../../modals/SendMessageModal";
import {SortArrows} from "../../SortArrows";
import {useSortProducts} from "../../../../mutations/useSortProducts.ts";
import {DuplicateProductModal} from "../../../modals/DuplicateProductModal";
import {useUpdateProduct} from "../../../../mutations/useUpdateProduct.ts";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {utcToTz} from "../../../../utilites/dates.ts";
import {productUpdatePayload} from "../../../../utilites/productUpdatePayload.ts";
import {InlineNumberEdit} from "../InlineNumberEdit.tsx";

interface SortableProductProps {
    product: Product;
    currencyCode: string;
    category: ProductCategory;
    categories: ProductCategory[];
}

const formatSaleDate = (date: string | Date, timezone?: string) => new Intl.DateTimeFormat(i18n.locale, {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: timezone,
}).format(new Date(typeof date === 'string' && !/(Z|[+-]\d\d:?\d\d)$/.test(date) ? `${date.replace(' ', 'T')}Z` : date));

const getChannel = (product: Product, boxOfficeEnabled: boolean): { label: string, tone: string, disabled?: boolean } => {
    const online = !product.is_hidden;
    const boxOffice = product.is_visible_at_box_office !== false;
    if (!boxOfficeEnabled) {
        if (online) return {label: t`Online`, tone: 'online'};
        return boxOffice
            ? {label: t`Box office turned off`, tone: 'hidden', disabled: true}
            : {label: t`Hidden`, tone: 'hidden'};
    }
    if (online && boxOffice) return {label: t`Online and box office`, tone: 'both'};
    if (online) return {label: t`Online`, tone: 'online'};
    if (boxOffice) return {label: t`Box office only`, tone: 'boxOffice'};
    return {label: t`Hidden`, tone: 'hidden'};
};

const getSaleText = (product: Product, timezone?: string) => {
    if (product.is_before_sale_start_date && product.sale_start_date) {
        return t`From ${formatSaleDate(product.sale_start_date, timezone)}`;
    }
    if (product.sale_end_date) {
        return product.is_after_sale_end_date
            ? t`Ended on ${formatSaleDate(product.sale_end_date, timezone)}`
            : t`Until ${formatSaleDate(product.sale_end_date, timezone)}`;
    }
    return t`Starting now, no end date`;
};

export const SortableProduct = ({product, currencyCode, category, categories}: SortableProductProps) => {
    const [isEditModalOpen, editModal] = useDisclosure(false);
    const [isDuplicateModalOpen, duplicateModal] = useDisclosure(false);
    const [isMessageModalOpen, messageModal] = useDisclosure(false);
    const [productId, setProductId] = useState<IdParam>();
    const deleteMutation = useDeleteProduct();
    const sortMutation = useSortProducts();
    const updateMutation = useUpdateProduct();
    const {data: event} = useGetEvent(product?.event_id);
    const {data: eventSettings} = useGetEventSettings(product?.event_id ? String(product.event_id) : undefined);
    const [endDraft, setEndDraft] = useState<string>('');
    const [saleOpen, setSaleOpen] = useState(false);

    if (!product?.id || !category?.id || !Array.isArray(category.products)) {
        return null;
    }

    const handleModalClick = (productId: IdParam, modal: { open: () => void }) => {
        setProductId(productId);
        modal.open();
    }

    const handleDeleteProduct = (productId: IdParam, eventId: IdParam) => {
        deleteMutation.mutate({productId, eventId}, {
            onSuccess: () => {
                showSuccess(t`Product deleted successfully`);
            },
            onError: (error: any) => {
                if (error.response?.status === 409) {
                    showError(error.response.data.message || t`This product cannot be deleted because it is associated with an order. You can hide it instead.`);
                }
            }
        });
    }

    const getStatusInfo = (product: Product) => {
        if (product.is_sales_paused) {
            return {label: t`Paused`, color: 'orange', variant: 'light' as const};
        }
        if (product.is_sold_out) {
            return {label: t`Sold Out`, color: 'red', variant: 'filled' as const};
        }
        if (product.is_before_sale_start_date) {
            return {label: t({message: 'Scheduled', context: 'ticket status'}), color: 'blue', variant: 'light' as const};
        }
        if (product.is_after_sale_end_date) {
            return {label: t`Ended`, color: 'gray', variant: 'light' as const};
        }
        if (product.is_hidden) {
            return product.is_visible_at_box_office !== false
                ? {label: t`On Sale`, color: 'green', variant: 'light' as const}
                : {label: t`Hidden`, color: 'gray', variant: 'outline' as const};
        }
        return product.is_available
            ? {label: t`On Sale`, color: 'green', variant: 'light' as const}
            : {label: t`Paused`, color: 'orange', variant: 'light' as const};
    }

    const getStatusTooltip = (product: Product) => {
        if (product.is_sales_paused) return t`Sales are paused online and at the box office`;
        if (product.is_sold_out) return t`This product is sold out`;
        if (product.is_before_sale_start_date) return t`On sale ${relativeDate(product.sale_start_date as string)}`;
        if (product.is_after_sale_end_date) return t`Sale ended ${relativeDate(product.sale_end_date as string)}`;
        if (product.is_hidden) return product.is_visible_at_box_office !== false ? t`Sold only at the box office` : t`Hidden from public view`;
        return product.is_available ? t`Currently available for purchase` : t`Sales are paused`;
    }

    const getPriceRange = (product: Product) => {
        const productPrices: ProductPrice[] = product.prices as ProductPrice[];
        if (!Array.isArray(productPrices) || productPrices.length === 0) {
            return {display: t`Price not set`, isFree: false};
        }

        if (product.type !== ProductPriceType.Tiered) {
            if (productPrices[0].price <= 0) {
                return {display: t`Free`, isFree: true};
            }
            return {display: formatCurrency(productPrices[0].price, currencyCode), isFree: false};
        }

        const prices = productPrices.map(productPrice => productPrice.price);
        const minPrice = Math.min(...prices);
        const maxPrice = Math.max(...prices);

        if (minPrice <= 0 && maxPrice <= 0) {
            return {display: t`Free`, isFree: true};
        }

        if (minPrice === maxPrice) {
            return {display: formatCurrency(minPrice, currencyCode), isFree: false};
        }

        return {
            display: `${formatCurrency(minPrice, currencyCode)} – ${formatCurrency(maxPrice, currencyCode)}`,
            isFree: false
        };
    }

    const hasTaxesOrFees = () => {
        return (product.taxes_and_fees && product.taxes_and_fees.length > 0) ||
            (product.tax_and_fee_ids && product.tax_and_fee_ids.length > 0);
    }

    const getTaxFeeTooltip = () => {
        if (!product.taxes_and_fees || product.taxes_and_fees.length === 0) {
            return t`Taxes & fees applied`;
        }
        return product.taxes_and_fees.map(tf => tf.name).join(', ');
    }

    const getSalesProgress = () => {
        const sold = Number(product.quantity_sold) || 0;
        const initial = product.initial_quantity_available;

        if (!initial || initial <= 0) {
            return null; // Unlimited
        }

        const percentage = Math.min((sold / initial) * 100, 100);
        const remaining = initial - sold;

        return {
            sold,
            total: initial,
            remaining,
            percentage,
            isLow: remaining > 0 && remaining <= 10,
        };
    }

    const handleSort = (productId: IdParam, direction: 'up' | 'down') => {
        if (!category?.products?.length || !product.event_id) return;

        const categoryIndex = categories.findIndex(cat => cat.id === category.id);
        const currentIndex = category.products.findIndex(p => p.id === productId);

        if (categoryIndex === -1 || currentIndex === -1) return;

        let updatedCategories = [...categories];

        if ((direction === 'up' && currentIndex === 0) ||
            (direction === 'down' && currentIndex === category.products.length - 1)) {

            const targetCategoryIndex = direction === 'up' ? categoryIndex - 1 : categoryIndex + 1;

            if (targetCategoryIndex < 0 || targetCategoryIndex >= categories.length) return;

            const sourceProducts = [...category.products];
            const [movedProduct] = sourceProducts.splice(currentIndex, 1);

            const targetCategory = categories[targetCategoryIndex];
            const targetProducts = [...(targetCategory.products || [])];

            const targetPosition = direction === 'up' ? targetProducts.length : 0;
            targetProducts.splice(targetPosition, 0, movedProduct);

            updatedCategories = categories.map((cat, index) => {
                if (index === categoryIndex) {
                    return {...cat, products: sourceProducts};
                }
                if (index === targetCategoryIndex) {
                    return {...cat, products: targetProducts};
                }
                return cat;
            });
        } else {
            const newIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
            if (newIndex < 0 || newIndex >= category.products.length) return;

            const updatedProducts = [...category.products];
            [updatedProducts[currentIndex], updatedProducts[newIndex]] =
                [updatedProducts[newIndex], updatedProducts[currentIndex]];

            updatedCategories = categories.map(cat =>
                cat.id === category.id ? {...cat, products: updatedProducts} : cat
            );
        }

        const sortedCategories = updatedCategories.map(cat => ({
            product_category_id: cat.id,
            sorted_products: (cat.products || []).map((prod, index) => ({
                id: prod.id,
                order: index + 1
            }))
        }));

        sortMutation.mutate({
            sortedCategories,
            eventId: product.event_id,
        }, {
            onSuccess: () => showSuccess(t`Products sorted successfully`),
            onError: () => showError(t`Failed to sort products`)
        });
    };

    const currentCategoryIndex = categories.findIndex(cat => cat.id === category.id);
    const currentProducts = category.products || [];
    const currentIndex = currentProducts.findIndex(p => p.id === product.id);

    const canMoveUp = currentIndex > 0 || currentCategoryIndex > 0;
    const canMoveDown = currentIndex < currentProducts.length - 1 ||
        currentCategoryIndex < categories.length - 1;

    const isTicket = product.product_type === ProductType.Ticket;
    const statusInfo = getStatusInfo(product);
    const priceInfo = getPriceRange(product);
    const salesProgress = getSalesProgress();

    const channel = getChannel(product, eventSettings?.box_office_enabled !== false);
    const singlePrice = product.prices?.length === 1 && product.type !== ProductPriceType.Tiered;
    const priceEditable = singlePrice && product.type === ProductPriceType.Paid;

    const quickUpdate = (overrides: (payload: Product) => Partial<Product>, message: string) => {
        if (!event) return Promise.resolve();
        const payload = productUpdatePayload(product, event.timezone);
        return updateMutation.mutateAsync({
            productId: product.id,
            eventId: product.event_id,
            productData: {...payload, ...overrides(payload)},
        }).then(() => showSuccess(message)).catch((error: any) => {
            showError(error?.response?.data?.message || t`The change could not be saved. Please try again.`);
            throw error;
        });
    };

    const updateFirstPrice = (changes: Partial<ProductPrice>) => (payload: Product) => ({
        prices: (payload.prices ?? []).map((price, index) => index === 0 ? {...price, ...changes} : price),
    });

    const initialQuantity = product.prices?.[0]?.initial_quantity_available ?? null;
    const saleText = getSaleText(product, event?.timezone);

    return (
        <>
            <div className={classNames(classes.ticketCard, {
                [classes.ticketCardSoldOut]: product.is_sold_out || product.is_sales_paused,
                [classes.ticketCardDisabled]: channel.disabled,
            })}>
                <div className={classes.ticketSort}>
                    <SortArrows
                        upArrowEnabled={canMoveUp}
                        downArrowEnabled={canMoveDown}
                        onSortUp={() => handleSort(product.id, 'up')}
                        onSortDown={() => handleSort(product.id, 'down')}
                        flexDirection={'column'}
                    />
                </div>

                <div className={classes.ticketBody}>
                    <div className={classes.ticketHead}>
                        <div className={classes.ticketTitleRow}>
                            <h3 className={classes.ticketTitle}>{product.title}</h3>
                            <span className={classNames(classes.channelTag, classes[`channelTag_${channel.tone}`])}>
                                {channel.label}
                            </span>
                            <Tooltip label={product.is_sales_paused ? t`Resume sales` : t`Pause sales online and at the box office`} withArrow>
                                <Switch
                                    size="md"
                                    color="green"
                                    checked={!product.is_sales_paused}
                                    disabled={updateMutation.isPending || !event}
                                    aria-label={product.is_sales_paused ? t`Resume sales` : t`Pause sales online and at the box office`}
                                    onChange={(changeEvent) => {
                                        const resume = changeEvent.currentTarget.checked;
                                        quickUpdate(() => ({is_sales_paused: !resume}), resume ? t`Sales resumed` : t`Sales paused`).catch(() => undefined);
                                    }}
                                />
                            </Tooltip>
                            {!isTicket && <span className={classes.extraTag}>{t`Product`}</span>}
                            {product.is_hidden_without_promo_code && (
                                <span className={classes.extraTag}><IconLock size={12}/> {t`Promo Only`}</span>
                            )}
                            {product.type === ProductPriceType.Donation && <span className={classes.extraTag}>{t`Donation`}</span>}
                            {product.waitlist_enabled && <span className={classes.extraTag}>{t`Waitlist Enabled`}</span>}
                            {product.is_highlighted && (
                                <span className={classes.extraTag}><IconSparkles size={12}/> {t`Highlighted`}</span>
                            )}
                        </div>
                        <div className={classes.ticketActions}>
                            <Tooltip label={getStatusTooltip(product)} withArrow>
                                <span className={classNames(classes.statusPill, classes[`statusPill_${statusInfo.color}`])}>
                                    {statusInfo.label}
                                </span>
                            </Tooltip>
                            <Button size="xs" variant="default" radius="md" leftSection={<IconPencil size={14}/>}
                                    onClick={() => handleModalClick(product.id, editModal)}>
                                {t`Edit`}
                            </Button>
                            <Menu shadow="md" width={220} position="bottom-end">
                                <Menu.Target>
                                    <button type="button" className={classes.moreButton} aria-label={t`More actions`}>
                                        <IconDots size={20}/>
                                    </button>
                                </Menu.Target>
                                <Menu.Dropdown>
                                    {isTicket && (
                                        <Menu.Item onClick={() => handleModalClick(product.id, messageModal)}
                                                   leftSection={<IconSend size={14}/>}>
                                            {t`Message Attendees`}
                                        </Menu.Item>
                                    )}
                                    <Menu.Item onClick={() => handleModalClick(product.id, duplicateModal)}
                                               leftSection={<IconCopyPlus size={14}/>}>
                                        {t`Duplicate`}
                                    </Menu.Item>
                                    <Menu.Divider/>
                                    <Menu.Item onClick={() => handleDeleteProduct(product.id, product.event_id)}
                                               color="red" leftSection={<IconTrash size={14}/>}>
                                        {t`Delete`}
                                    </Menu.Item>
                                </Menu.Dropdown>
                            </Menu>
                        </div>
                    </div>

                    <div className={classes.ticketDetails}>
                        <div>
                            <span className={classes.ticketLabel}>{t`Price`}</span>
                            {priceEditable ? (
                                <InlineNumberEdit
                                    label={t`Edit the price`}
                                    value={Number(product.prices?.[0]?.price ?? 0)}
                                    decimals={2}
                                    suffix=" €"
                                    display={<span className={classes.ticketPrice}>{priceInfo.display}</span>}
                                    onSave={(value) => quickUpdate(updateFirstPrice({price: value ?? 0}), t`Price updated`)}
                                />
                            ) : (
                                <span className={classNames(classes.ticketPrice, {[classes.freePrice]: priceInfo.isFree})}>
                                    {priceInfo.display}
                                </span>
                            )}
                            {hasTaxesOrFees() && (
                                <Tooltip label={getTaxFeeTooltip()} withArrow>
                                    <span className={classes.ticketMuted}><IconReceipt size={13}/> {t`+Tax/Fees`}</span>
                                </Tooltip>
                            )}
                        </div>
                        <div>
                            <span className={classes.ticketLabel}>{t({message: 'Sold', context: 'ticket card'})}</span>
                            {salesProgress ? (
                                <>
                                    <span className={classes.ticketSold}>
                                        <strong>{salesProgress.sold}</strong> /{' '}
                                        {singlePrice ? (
                                            <InlineNumberEdit
                                                label={t`Edit the quantity`}
                                                value={initialQuantity}
                                                allowEmpty
                                                placeholder={t`Unlimited`}
                                                display={<span>{salesProgress.total}</span>}
                                                onSave={(value) => quickUpdate(updateFirstPrice({initial_quantity_available: value ?? undefined}), t`Quantity updated`)}
                                            />
                                        ) : salesProgress.total}
                                        <span className={classes.ticketMuted}>
                                            · {salesProgress.remaining > 0
                                                ? t`${salesProgress.remaining} places left`
                                                : t`Sold out`}
                                        </span>
                                    </span>
                                    <Progress value={salesProgress.percentage} size="sm" radius="xl"
                                              color={salesProgress.percentage >= 100 ? 'red' : salesProgress.isLow ? 'orange' : 'violet'}
                                              className={classes.ticketBar}/>
                                </>
                            ) : (
                                <span className={classes.ticketSold}>
                                    <strong>{Number(product.quantity_sold) || 0}</strong>
                                    <span className={classes.ticketMuted}>
                                        ·{' '}
                                        {singlePrice ? (
                                            <InlineNumberEdit
                                                label={t`Edit the quantity`}
                                                value={null}
                                                allowEmpty
                                                placeholder={t`Unlimited`}
                                                display={<span>{t`Unlimited`}</span>}
                                                onSave={(value) => quickUpdate(updateFirstPrice({initial_quantity_available: value ?? undefined}), t`Quantity updated`)}
                                            />
                                        ) : t`Unlimited`}
                                    </span>
                                </span>
                            )}
                        </div>
                        <div>
                            <span className={classes.ticketLabel}>{t({message: 'Sale', context: 'ticket card'})}</span>
                            <Popover opened={saleOpen} onChange={setSaleOpen} position="bottom-start" withArrow shadow="md" trapFocus>
                                <Popover.Target>
                                    <button type="button" className={classes.inlineEdit} aria-label={t`Set an end date`}
                                            onClick={() => {
                                                setEndDraft(event ? (utcToTz(product.sale_end_date, event.timezone) ?? '') : '');
                                                setSaleOpen((open) => !open);
                                            }}>
                                        <span className={classes.ticketSale}>{saleText}</span>
                                        <IconPencil size={13} className={classes.inlineEditIcon}/>
                                    </button>
                                </Popover.Target>
                                <Popover.Dropdown>
                                    <div className={classes.salePopover}>
                                        <label className={classes.salePopoverLabel} htmlFor={`sale-end-${product.id}`}>
                                            {t`End of sale`}
                                        </label>
                                        <input id={`sale-end-${product.id}`} type="datetime-local" className={classes.salePopoverInput}
                                               value={endDraft} onChange={(changeEvent) => setEndDraft(changeEvent.currentTarget.value)}/>
                                        <div className={classes.salePopoverActions}>
                                            {product.sale_end_date && (
                                                <Button size="xs" variant="subtle" color="gray" loading={updateMutation.isPending}
                                                        onClick={() => quickUpdate(() => ({sale_end_date: undefined}), t`End date removed`)
                                                            .then(() => setSaleOpen(false)).catch(() => undefined)}>
                                                    {t`No end date`}
                                                </Button>
                                            )}
                                            <Button size="xs" radius="md" disabled={!endDraft} loading={updateMutation.isPending}
                                                    onClick={() => quickUpdate(() => ({sale_end_date: endDraft}), t`End date saved`)
                                                        .then(() => setSaleOpen(false)).catch(() => undefined)}>
                                                {t`Save`}
                                            </Button>
                                        </div>
                                    </div>
                                </Popover.Dropdown>
                            </Popover>
                        </div>
                    </div>
                </div>
            </div>

            {isDuplicateModalOpen &&
                <DuplicateProductModal originalProductId={productId} onClose={duplicateModal.close}/>}
            {isEditModalOpen && <EditProductModal productId={productId} onClose={editModal.close}/>}
            {isMessageModalOpen && (
                <SendMessageModal
                    onClose={messageModal.close}
                    productId={productId}
                    messageType={MessageType.TicketHolders}
                />
            )}
        </>
    );
};
