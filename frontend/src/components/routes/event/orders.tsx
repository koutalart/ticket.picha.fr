import React, {useState} from "react";
import {useParams} from "react-router";
import {Button, Skeleton} from "@mantine/core";
import {IconDownload} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent";
import {useGetEventOrders} from "../../../queries/useGetEventOrders";
import {useGetEventStats} from "../../../queries/useGetEventStats";
import {SearchBarWrapper} from "../../common/SearchBar";
import {Pagination} from "../../common/Pagination";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync";
import {IdParam, QueryFilterOperator, QueryFilters} from "../../../types";
import {orderClient} from "../../../api/order.client";
import {downloadBinary} from "../../../utilites/download";
import {withLoadingNotification} from "../../../utilites/withLoadingNotification.tsx";
import {formatCurrency} from "../../../utilites/currency.ts";
import {formatNumber} from "../../../utilites/helpers.ts";
import {OrderList} from "./OrdersPage/OrderList.tsx";
import classes from "./OrdersPage/OrdersPage.module.scss";

type StatusTab = 'all' | 'completed' | 'awaiting' | 'cancelled' | 'refunded';
type ChannelTab = 'all' | 'online' | 'box_office';

const statusFilters: Record<StatusTab, Record<string, unknown>> = {
    all: {},
    completed: {status: {operator: QueryFilterOperator.Equals, value: 'COMPLETED'}},
    awaiting: {status: {operator: QueryFilterOperator.Equals, value: 'AWAITING_OFFLINE_PAYMENT'}},
    cancelled: {status: {operator: QueryFilterOperator.Equals, value: 'CANCELLED'}},
    refunded: {refund_status: {operator: QueryFilterOperator.In, value: ['REFUNDED', 'PARTIALLY_REFUNDED']}},
};

const channelFilters: Record<ChannelTab, Record<string, unknown>> = {
    all: {},
    online: {is_manually_created: {operator: QueryFilterOperator.Equals, value: 'false'}},
    box_office: {is_manually_created: {operator: QueryFilterOperator.Equals, value: 'true'}},
};

const countQuery = (filterFields: Record<string, unknown>): QueryFilters => ({perPage: 1, filterFields} as QueryFilters);

export const Orders: React.FC = () => {
    const {eventId} = useParams<{ eventId: string }>();
    const {data: event} = useGetEvent(eventId);
    const {data: stats} = useGetEventStats(eventId, 'event');
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const ordersQuery = useGetEventOrders(eventId, searchParams as QueryFilters);
    const orders = ordersQuery.data?.data;
    const pagination = ordersQuery.data?.meta;
    const [exporting, setExporting] = useState(false);

    const completedCount = useGetEventOrders(eventId, countQuery(statusFilters.completed)).data?.meta?.total;
    const awaitingCount = useGetEventOrders(eventId, countQuery(statusFilters.awaiting)).data?.meta?.total;
    const refundedCount = useGetEventOrders(eventId, countQuery(statusFilters.refunded)).data?.meta?.total;

    const fields = (searchParams.filterFields ?? {}) as Record<string, { value?: unknown }>;
    const statusTab: StatusTab = fields.refund_status ? 'refunded'
        : fields.status?.value === 'COMPLETED' ? 'completed'
            : fields.status?.value === 'AWAITING_OFFLINE_PAYMENT' ? 'awaiting'
                : fields.status?.value === 'CANCELLED' ? 'cancelled'
                    : 'all';
    const channelTab: ChannelTab = fields.is_manually_created?.value === 'true' ? 'box_office'
        : fields.is_manually_created?.value === 'false' ? 'online'
            : 'all';

    const applyFilters = (status: StatusTab, channel: ChannelTab) => setSearchParams({
        ...searchParams,
        pageNumber: 1,
        filterFields: {...statusFilters[status], ...channelFilters[channel]},
    } as QueryFilters, true);

    const exportOrders = (id: IdParam) => withLoadingNotification(async () => {
        setExporting(true);
        const blob = await orderClient.exportOrders(id);
        downloadBinary(blob, 'commandes.xlsx');
    }, {
        loading: {title: t`Exporting Orders`, message: t`Please wait while we prepare your orders for export...`},
        success: {title: t`Orders Exported`, message: t`Your orders have been exported successfully.`, onRun: () => setExporting(false)},
        error: {title: t`Failed to export orders`, message: t`Please try again.`, onRun: () => setExporting(false)},
    });

    const figures: { key: StatusTab | 'gross', label: string, value?: string, highlight?: boolean }[] = [
        {key: 'gross', label: t`Collected`, value: stats ? formatCurrency(stats.total_gross_sales - stats.total_refunded, event?.currency) : undefined},
        {key: 'completed', label: t({message: 'Completed', context: 'orders figure'}), value: completedCount !== undefined ? String(formatNumber(completedCount)) : undefined},
        {
            key: 'awaiting',
            label: t({message: 'Awaiting payment', context: 'order status'}),
            value: awaitingCount !== undefined ? String(formatNumber(awaitingCount)) : undefined,
            highlight: Number(awaitingCount) > 0,
        },
        {key: 'refunded', label: t({message: 'Refunded', context: 'orders figure'}), value: refundedCount !== undefined ? String(formatNumber(refundedCount)) : undefined},
    ];

    const statusTabs: { value: StatusTab, label: string }[] = [
        {value: 'all', label: t({message: 'All', context: 'orders filter'})},
        {value: 'completed', label: t({message: 'Completed', context: 'orders filter'})},
        {value: 'awaiting', label: t({message: 'Awaiting', context: 'orders filter'})},
        {value: 'cancelled', label: t({message: 'Cancelled', context: 'orders filter'})},
        {value: 'refunded', label: t({message: 'Refunded', context: 'orders filter'})},
    ];

    const channelTabs: { value: ChannelTab, label: string }[] = [
        {value: 'online', label: t`Online`},
        {value: 'box_office', label: t`Box Office`},
    ];

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <div>
                    <h1 className={classes.title}><Trans>Orders</Trans></h1>
                    <p className={classes.subtitle}><Trans>View, refund and resend tickets.</Trans></p>
                </div>
                <Button variant="default" radius="md" leftSection={<IconDownload size={16}/>} loading={exporting}
                        onClick={() => exportOrders(eventId as IdParam)}>
                    <Trans>Export (Excel)</Trans>
                </Button>
            </header>

            <section className={classes.figures}>
                {figures.map((figure) => {
                    const clickable = figure.key !== 'gross';
                    const content = (
                        <>
                            <span className={classes.figureLabel}>{figure.label}</span>
                            {figure.value === undefined
                                ? <Skeleton height={26} width={70} radius="sm"/>
                                : <span className={classes.figureValue}>{figure.value}</span>}
                        </>
                    );
                    return clickable ? (
                        <button key={figure.key} type="button"
                                className={`${classes.figure} ${figure.highlight ? classes.figureWarning : ''} ${statusTab === figure.key ? classes.figureActive : ''}`}
                                onClick={() => applyFilters(figure.key as StatusTab, channelTab)}>
                            {content}
                        </button>
                    ) : <div key={figure.key} className={classes.figure}>{content}</div>;
                })}
            </section>

            <div className={classes.filters}>
                <div className={classes.chips}>
                    {statusTabs.map((tab) => (
                        <button key={tab.value} type="button"
                                className={statusTab === tab.value ? classes.chipActive : classes.chip}
                                onClick={() => applyFilters(tab.value, channelTab)}>
                            {tab.label}
                        </button>
                    ))}
                    <span className={classes.chipDivider}/>
                    {channelTabs.map((tab) => (
                        <button key={tab.value} type="button"
                                className={channelTab === tab.value ? classes.chipActive : classes.chip}
                                onClick={() => applyFilters(statusTab, channelTab === tab.value ? 'all' : tab.value)}>
                            {tab.label}
                        </button>
                    ))}
                </div>
                <div className={classes.search}>
                    <SearchBarWrapper
                        placeholder={t`Search a name, an email or an order number…`}
                        setSearchParams={setSearchParams}
                        searchParams={searchParams}
                        pagination={pagination}
                    />
                </div>
            </div>

            {(!orders || ordersQuery.isFetching) && !orders && (
                <div className={classes.skeletons}>
                    {[...Array(4)].map((_, index) => <Skeleton key={index} height={64} radius="md"/>)}
                </div>
            )}

            {orders && event && orders.length > 0 && <OrderList orders={orders} event={event}/>}

            {orders && orders.length === 0 && (
                <NoResultsSplash
                    imageHref={'/blank-slate/orders.svg'}
                    heading={searchParams.query || statusTab !== 'all' || channelTab !== 'all'
                        ? t`No order matches these filters`
                        : t`No orders to show`}
                    subHeading={<p>{t`Your orders will appear here once they start rolling in.`}</p>}
                />
            )}

            {!!orders?.length && Number(pagination?.last_page) > 1 && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}
        </div>
    );
};

export default Orders;
