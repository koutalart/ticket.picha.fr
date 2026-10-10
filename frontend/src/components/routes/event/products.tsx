import {useParams} from "react-router";
import {useDisclosure} from "@mantine/hooks";
import {Button} from "@mantine/core";
import {IconCategory, IconPlus} from "@tabler/icons-react";
import {CreateProductModal} from "../../modals/CreateProductModal";
import {ProductCategoryList} from "../../common/ProductsTable";
import {TableSkeleton} from "../../common/TableSkeleton";
import {t, Trans} from "@lingui/macro";
import classes from "./ProductsPage.module.scss";
import {QuickAddTicket} from "./QuickAddTicket.tsx";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {formatNumber} from "../../../utilites/helpers.ts";
import {useUrlHash} from "../../../hooks/useUrlHash.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetEventProductCategories} from "../../../queries/useGetProductCategories.ts";
import {SearchBar} from "../../common/SearchBar";
import {useState} from "react";
import {CreateProductCategoryModal} from "../../modals/CreateProductCategoryModal";
import {IdParam} from "../../../types.ts";

export const Products = () => {
    const [createProductModalOpen, {
        open: openCreateProductModal,
        close: closeCreateProductModal
    }] = useDisclosure(false);
    const [createProductCategoryModalOpen, {
        open: openCreateProductCategoryModal,
        close: closeCreateProductCategoryModal
    }] = useDisclosure(false);
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedCategoryId, setSelectedCategoryId] = useState<IdParam>(null);

    const productCategoriesQuery = useGetEventProductCategories(eventId);
    const productCategories = productCategoriesQuery?.data?.data;

    useUrlHash('create-product', () => openCreateProductModal());

    const openCreateProduct = (categoryId: IdParam) => {
        setSelectedCategoryId(() => categoryId);
        openCreateProductModal();
    }

    const productCount = (productCategories ?? []).reduce((total, category) => total + (category.products?.length ?? 0), 0);
    const {data: stats} = useGetEventStats(eventId, 'event');
    const tickets = (productCategories ?? []).flatMap((category) => category.products ?? []);
    const sold = tickets.reduce((total, product) => total + (Number(product.quantity_sold) || 0), 0);
    const unlimited = tickets.some((product) => !product.initial_quantity_available);
    const capacity = tickets.reduce((total, product) => total + (Number(product.initial_quantity_available) || 0), 0);
    const almostGone = tickets.filter((product) => {
        const total = Number(product.initial_quantity_available) || 0;
        const left = total - (Number(product.quantity_sold) || 0);
        return total > 0 && left > 0 && left <= Math.max(10, total * 0.1);
    });

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <div>
                    <h1 className={classes.title}><Trans>Tickets</Trans></h1>
                    <p className={classes.subtitle}><Trans>What you sell, at what price and in what quantity.</Trans></p>
                </div>
                <div className={classes.headerActions}>
                    <Button variant="default" radius="md" leftSection={<IconCategory size={16}/>}
                            onClick={openCreateProductCategoryModal}>
                        <Trans>Category</Trans>
                    </Button>
                    <Button radius="md" leftSection={<IconPlus size={16}/>} onClick={() => openCreateProduct(undefined)}>
                        <Trans>Add a ticket</Trans>
                    </Button>
                </div>
            </header>

            {tickets.length > 0 && (
                <section className={classes.summary}>
                    <div className={classes.summaryItem}>
                        <span className={classes.summaryLabel}><Trans>Tickets sold</Trans></span>
                        <span className={classes.summaryValue}>
                            {formatNumber(sold)}
                            <span className={classes.summaryMuted}> / {unlimited ? t`unlimited` : formatNumber(capacity)}</span>
                        </span>
                    </div>
                    <div className={classes.summaryItem}>
                        <span className={classes.summaryLabel}>{t({message: 'Revenue', context: 'chart'})}</span>
                        <span className={classes.summaryValue}>
                            {stats ? formatCurrency(stats.total_gross_sales - stats.total_refunded, event?.currency) : '…'}
                        </span>
                    </div>
                    <div className={`${classes.summaryItem} ${almostGone.length > 0 ? classes.summaryWarning : ''}`}>
                        <span className={classes.summaryLabel}><Trans>Almost sold out</Trans></span>
                        <span className={classes.summaryValue}>
                            {almostGone.length > 0 ? almostGone.map((product) => product.title).join(', ') : t`None`}
                        </span>
                    </div>
                </section>
            )}

            {productCount >= 8 && (
                <div className={classes.search}>
                    <SearchBar
                        onClear={() => setSearchTerm('')}
                        placeholder={t`Search a ticket`}
                        value={searchTerm}
                        onChange={(event) => setSearchTerm(event.target.value)}
                    />
                </div>
            )}

            <TableSkeleton isVisible={!productCategories || !event}/>

            {(event && productCategories)
                && (<ProductCategoryList
                        initialCategories={productCategories}
                        event={event}
                        searchTerm={searchTerm}
                        onCreateOpen={openCreateProduct}
                    />
                )}

            {productCategories && productCategories.length > 0 && eventId && (
                <QuickAddTicket eventId={eventId} categories={productCategories} onMoreOptions={() => openCreateProduct(undefined)}/>
            )}

            {createProductModalOpen &&
                <CreateProductModal selectedCategoryId={selectedCategoryId} onClose={closeCreateProductModal}
                                    isOpen={createProductModalOpen}/>}
            {createProductCategoryModalOpen && <CreateProductCategoryModal onClose={closeCreateProductCategoryModal}
                                                                           isOpen={createProductCategoryModalOpen}/>}
        </div>
    );
};

export default Products;
