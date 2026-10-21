import {useRef, useState} from 'react';
import {NavLink, useParams} from "react-router";
import {AreaChart} from "@mantine/charts";
import {Button, SegmentedControl, Skeleton} from "@mantine/core";
import {useDisclosure} from "@mantine/hooks";
import {IconArrowRight, IconEye, IconShare} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetEventStats} from "../../../../queries/useGetEventStats.ts";
import {useGetEventOrders} from "../../../../queries/useGetEventOrders.ts";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatDateWithLocale, relativeDate} from "../../../../utilites/dates.ts";
import {formatNumber, getProductsFromEvent} from "../../../../utilites/helpers.ts";
import {isFreeEvent} from "../../../../utilites/eventPricing.ts";
import {eventHomepageUrl} from "../../../../utilites/urlHelper.ts";
import {ShareModal} from "../../../modals/ShareModal";
import {Event, EventLifecycleStatus, EventStatus, Order, Product} from "../../../../types.ts";
import classes from "./EventDashboard.module.scss";

type ChartMode = 'tickets' | 'revenue';

const priceCount = (product: Product, field: 'quantity_sold' | 'initial_quantity_available') => {
    const prices = product.prices ?? [];
    if (prices.length === 0) return product[field] ?? null;
    if (field === 'initial_quantity_available' && prices.some((price) => price.initial_quantity_available == null)) return null;
    return prices.reduce((total, price) => total + Number(price[field] ?? 0), 0);
};

const channelOf = (product: Product) => {
    if (product.is_hidden && product.is_visible_at_box_office !== false) return {label: t`Box Office`, tone: 'boxOffice'};
    if (!product.is_hidden && product.is_visible_at_box_office === false) return {label: t`Online`, tone: 'online'};
    return {label: t`Online and box office`, tone: 'both'};
};

const eventWhen = (event: Event) => {
    const date = new Intl.DateTimeFormat(i18n.locale, {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: event.timezone,
    }).format(new Date(event.start_date));
    return date.charAt(0).toUpperCase() + date.slice(1);
};

export const EventDashboard = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const {data: settings} = useGetEventSettings(eventId);
    const [shareOpened, share] = useDisclosure(false);
    const [chartMode, setChartMode] = useState<ChartMode>('tickets');

    const defaultRange = useRef<string | null>(null);
    if (event && !defaultRange.current) {
        defaultRange.current = event.lifecycle_status === EventLifecycleStatus.ENDED || event.status === EventStatus.ARCHIVED
            ? 'event'
            : 'last_30_days';
    }
    const [dateRange, setDateRange] = useState<string | null>(null);
    const range = dateRange ?? defaultRange.current ?? 'last_30_days';

    const {data: stats} = useGetEventStats(eventId, range, !!defaultRange.current);
    const {data: ordersResponse, isLoading: ordersLoading} = useGetEventOrders(eventId, {
        perPage: 5, sortBy: 'created_at', sortDirection: 'desc',
    });
    const orders = ordersResponse?.data ?? [];

    if (!event) {
        return (
            <div className={classes.page}>
                <Skeleton height={60} width="60%" mb="xl"/>
                <Skeleton height={90} mb="xl"/>
                <Skeleton height={260}/>
            </div>
        );
    }

    const products = (getProductsFromEvent(event) ?? []).filter((product) => product.product_type === 'TICKET');
    const isFree = isFreeEvent(event);
    const isLive = event.status === 'LIVE';
    const isOngoing = isLive && event.lifecycle_status === EventLifecycleStatus.ONGOING;
    const location = settings?.location_details ?? event.settings?.location_details;
    const place = settings?.is_online_event ? t`Online` : location?.venue_name || location?.city;

    const checkedIn = stats?.check_in_stats?.total_checked_in_attendees ?? 0;
    const attendees = stats?.check_in_stats?.total_attendees ?? stats?.total_attendees_registered ?? 0;
    const attendanceRate = attendees > 0 ? Math.round(checkedIn / attendees * 100) : 0;

    const figures = isFree ? [
        {label: t`Registrations`, value: stats ? formatNumber(stats.total_attendees_registered) : null},
        {label: t`Present`, value: stats ? formatNumber(checkedIn) : null},
        {label: t`Attendance rate`, value: stats ? `${attendanceRate} %` : null},
        {label: t`Page views`, value: stats ? formatNumber(stats.total_views) : null},
    ] : [
        {
            label: t`Gross sales`,
            value: stats ? formatCurrency(stats.total_gross_sales, event.currency) : null,
            note: stats && stats.total_refunded > 0 ? t`including ${formatCurrency(stats.total_refunded, event.currency)} refunded` : null,
            pending: Number(stats?.total_awaiting_payment ?? 0) > 0
                ? t`+ ${formatCurrency(Number(stats?.total_awaiting_payment), event.currency)} awaiting payment collection`
                : null,
        },
        {label: t`Tickets sold`, value: stats ? formatNumber(stats.total_products_sold) : null},
        {label: t`Orders`, value: stats ? formatNumber(stats.total_orders) : null},
        {label: t`Page views`, value: stats ? formatNumber(stats.total_views) : null},
    ];

    const attendanceFigures: typeof figures = settings?.allow_attendee_attendance_response ? [
        {label: t`Attendance confirmed`, value: stats ? formatNumber(stats.total_attendance_confirmed ?? 0) : null},
        {label: t`Cancelled by attendee`, value: stats ? formatNumber(stats.total_cancelled_by_attendee ?? 0) : null},
    ] : [];

    const chartData = stats?.daily_stats.map((day) => ({
        date: formatDateWithLocale(day.date, 'chartDate', event.timezone),
        products_sold: day.products_sold,
        attendees_registered: day.attendees_registered,
        total_sales_gross: day.total_sales_gross,
        total_refunded: day.total_refunded,
    })) ?? [];
    const mode: ChartMode = isFree ? 'tickets' : chartMode;

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <div className={classes.headerText}>
                    <span className={classes.kicker}><Trans>Dashboard</Trans></span>
                    <div className={classes.titleRow}>
                        <h1 className={classes.title}>{event.title}</h1>
                        {isOngoing ? (
                            <NavLink to={`/manage/event/${eventId}/box-office`} className={classes.liveButton}
                                     title={t`The event is happening now. Open the box office`}>
                                <span className={classes.liveDot}/>
                                {t({message: 'Live', context: 'ongoing event'})}
                            </NavLink>
                        ) : (
                            <span className={isLive ? classes.badgeLive : classes.badgeDraft}>
                                {isLive ? t`Online` : t`Draft`}
                            </span>
                        )}
                    </div>
                    <p className={classes.meta}>
                        {eventWhen(event)}{place && <> · {place}</>}
                    </p>
                </div>
                <div className={classes.headerActions}>
                    <Button component="a" href={`/event/${eventId}/${event.slug}`} target="_blank" variant="subtle"
                            radius="md" leftSection={<IconEye size={18}/>}>
                        {t({message: 'Preview', context: 'event menu'})}
                    </Button>
                    <Button variant="default" radius="md" leftSection={<IconShare size={18}/>} onClick={share.open}>
                        {t({message: 'Share', context: 'event menu'})}
                    </Button>
                </div>
            </header>

            <section className={classes.figures}>
                {figures.concat(attendanceFigures).map((figure) => (
                    <div key={figure.label} className={classes.figure}>
                        <span className={classes.figureLabel}>{figure.label}</span>
                        {figure.value === null
                            ? <Skeleton height={30} width={80} radius="sm"/>
                            : <span className={classes.figureValue}>{figure.value}</span>}
                        {'note' in figure && figure.note && <span className={classes.figureNote}>{figure.note}</span>}
                        {'pending' in figure && figure.pending && <span className={classes.figurePending}>{figure.pending}</span>}
                    </div>
                ))}
            </section>

            <div className={classes.columns}>
                <section className={classes.section}>
                    <div className={classes.sectionHead}>
                        <h2 className={classes.sectionTitle}><Trans>Tickets and capacity</Trans></h2>
                        <NavLink to={`/manage/event/${eventId}/products`} className={classes.seeAll}>
                            <Trans>Manage</Trans> <IconArrowRight size={16}/>
                        </NavLink>
                    </div>
                    {products.length === 0 && (
                        <p className={classes.muted}><Trans>No tickets yet.</Trans></p>
                    )}
                    {products.map((product) => {
                        const sold = priceCount(product, 'quantity_sold') ?? 0;
                        const total = priceCount(product, 'initial_quantity_available');
                        const channel = channelOf(product);
                        const percent = total ? Math.min(100, Math.round(sold / total * 100)) : 0;
                        return (
                            <div key={product.id} className={classes.ticket}>
                                <div className={classes.ticketHead}>
                                    <span className={classes.ticketName}>
                                        {product.title}
                                        <span className={`${classes.channel} ${classes[`channel_${channel.tone}`]}`}>{channel.label}</span>
                                    </span>
                                    <span className={classes.ticketCount}>
                                        {formatNumber(sold)}{total !== null && <> / {formatNumber(total)}</>}
                                    </span>
                                </div>
                                {total !== null ? (
                                    <>
                                        <div className={classes.bar}><div style={{width: `${percent}%`}}/></div>
                                        <span className={classes.ticketRemaining}>
                                            {total - sold > 0
                                                ? <Trans>{formatNumber(total - sold)} places left</Trans>
                                                : <Trans>Sold out</Trans>}
                                        </span>
                                    </>
                                ) : (
                                    <span className={classes.ticketRemaining}><Trans>Unlimited</Trans></span>
                                )}
                            </div>
                        );
                    })}
                </section>

                <section className={classes.entries}>
                    <h2 className={classes.sectionTitle}><Trans>Entries</Trans></h2>
                    <div className={classes.entriesValue}>
                        {formatNumber(checkedIn)} <span><Trans>/ {formatNumber(attendees)} scanned</Trans></span>
                    </div>
                    <div className={`${classes.bar} ${classes.barGreen}`}><div style={{width: `${attendanceRate}%`}}/></div>
                    <p className={classes.entriesHint}><Trans>Updates live during the event.</Trans></p>
                    <NavLink to={`/manage/event/${eventId}/check-in`} className={classes.seeAll}>
                        <Trans>Check-in lists</Trans> <IconArrowRight size={16}/>
                    </NavLink>
                </section>
            </div>

            <section className={classes.section}>
                <div className={classes.chartHead}>
                    <h2 className={classes.sectionTitle}>{isFree ? <Trans>Registrations over time</Trans> : <Trans>Sales over time</Trans>}</h2>
                    <div className={classes.chartControls}>
                        {!isFree && (
                            <SegmentedControl size="xs" radius="md" value={chartMode}
                                              onChange={(value) => setChartMode(value as ChartMode)}
                                              data={[{value: 'tickets', label: t`Tickets`}, {value: 'revenue', label: t({message: 'Revenue', context: 'chart'})}]}/>
                        )}
                        <SegmentedControl size="xs" radius="md" value={range} onChange={setDateRange}
                                          data={[
                                              {value: 'last_30_days', label: t`Recent`},
                                              {value: 'week', label: t`Week`},
                                              {value: 'month', label: t`Month`},
                                              {value: 'event', label: t`Event`},
                                          ]}/>
                    </div>
                </div>
                <AreaChart
                    h={240}
                    data={chartData}
                    dataKey="date"
                    curveType="monotone"
                    tickLine="none"
                    gridAxis="x"
                    withDots={false}
                    valueFormatter={mode === 'revenue' ? (value) => formatCurrency(value, event.currency) : undefined}
                    series={mode === 'revenue' ? [
                        {name: 'total_sales_gross', label: t`Gross sales`, color: 'violet.6'},
                        {name: 'total_refunded', label: t`Total Refunded`, color: 'red.5'},
                    ] : isFree ? [
                        {name: 'attendees_registered', label: t`Registrations`, color: 'violet.6'},
                    ] : [
                        {name: 'products_sold', label: t`Tickets sold`, color: 'violet.6'},
                    ]}
                />
            </section>

            <section className={classes.section}>
                <div className={classes.sectionHead}>
                    <h2 className={classes.sectionTitle}>{isFree ? <Trans>Latest registrations</Trans> : <Trans>Latest orders</Trans>}</h2>
                    <NavLink to={`/manage/event/${eventId}/orders`} className={classes.seeAll}>
                        <Trans>See all</Trans> <IconArrowRight size={16}/>
                    </NavLink>
                </div>
                {ordersLoading && <Skeleton height={56}/>}
                {!ordersLoading && orders.length === 0 && <p className={classes.muted}>{isFree ? <Trans>No registrations yet.</Trans> : <Trans>No orders yet.</Trans>}</p>}
                {orders.map((order: Order) => (
                    <NavLink key={order.id} to={`/manage/event/${eventId}/orders#order-${order.id}`} className={classes.order}>
                        <span className={classes.orderWho}>
                            {order.first_name} {order.last_name}
                            <span className={classes.orderWhen}>· {relativeDate(order.created_at)}</span>
                        </span>
                        <span className={classes.orderAmount}>{formatCurrency(order.total_gross, order.currency)}</span>
                    </NavLink>
                ))}
            </section>

            <ShareModal
                url={eventHomepageUrl(event)}
                title={event.title}
                modalTitle={t`Share Event`}
                opened={shareOpened}
                onClose={share.close}
            />
        </div>
    );
};

export default EventDashboard;
