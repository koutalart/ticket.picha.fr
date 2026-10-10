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
import {Button, Menu, Progress, Tooltip} from "@mantine/core";
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

interface SortableProductProps {
    product: Product;
    currencyCode: string;
    category: ProductCategory;
    categories: ProductCategory[];
}

const formatSaleDate = (date: string | Date) => new Intl.DateTimeFormat(i18n.locale, {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
}).format(new Date(date));

const getChannel = (product: Product): { label: string, tone: string } => {
    const online = !product.is_hidden;
    const boxOffice = product.is_visible_at_box_office !== false;
    if (online && boxOffice) return {label: t`Online and box office`, tone: 'both'};
    if (online) return {label: t`Online`, tone: 'online'};
    if (boxOffice) return {label: t`Box office only`, tone: 'boxOffice'};
    return {label: t`Hidden`, tone: 'hidden'};
};

const getSaleText = (product: Product) => {
    if (product.is_before_sale_start_date && product.sale_start_date) {
        return t`From ${formatSaleDate(product.sale_start_date)}`;
    }
    if (product.sale_end_date) {
        return product.is_after_sale_end_date
            ? t`Ended on ${formatSaleDate(product.sale_end_date)}`
            : t`Until ${formatSaleDate(product.sale_end_date)}`;
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

    const channel = getChannel(product);
    const saleText = getSaleText(product);

    return (
        <>
            <div className={classNames(classes.ticketCard, {[classes.ticketCardSoldOut]: product.is_sold_out})}>
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
                            <span className={classNames(classes.ticketPrice, {[classes.freePrice]: priceInfo.isFree})}>
                                {priceInfo.display}
                            </span>
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
                                        <strong>{salesProgress.sold}</strong> / {salesProgress.total}
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
                                    <span className={classes.ticketMuted}> · {t`Unlimited`}</span>
                                </span>
                            )}
                        </div>
                        <div>
                            <span className={classes.ticketLabel}>{t({message: 'Sale', context: 'ticket card'})}</span>
                            <span className={classes.ticketSale}>{saleText}</span>
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
