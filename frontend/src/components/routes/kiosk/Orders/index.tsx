import {useMemo, useState} from "react";
import {useParams} from "react-router";
import {plural, t, Trans} from "@lingui/macro";
import {Button, Chip, Drawer, Loader, SegmentedControl, TextInput} from "@mantine/core";
import {useDebouncedValue} from "@mantine/hooks";
import {IconDoorEnter, IconId, IconPrinter, IconSearch, IconUsersGroup} from "@tabler/icons-react";
import dayjs from "dayjs";
import {utcToTz} from "../../../../utilites/dates.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {
    boxOfficeClient,
    BoxOfficeAttendeeSearchResult,
    BoxOfficeCheckInResult,
    BoxOfficeOrderChannel,
    BoxOfficeOrderListItem,
} from "../../../../api/box-office.client.ts";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {useGetBoxOfficeOrders} from "../../../../queries/useGetBoxOfficeOrders.ts";
import {useGetBoxOfficeOrder} from "../../../../queries/useGetBoxOfficeOrder.ts";
import {useCheckInBoxOfficeAttendee} from "../../../../mutations/useCheckInBoxOfficeAttendee.ts";
import {KioskPrintOutput, useKioskSettings} from "../../../../hooks/useKioskSettings.ts";
import {useKioskTicketPrinter} from "../../../../hooks/useKioskTicketPrinter.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./Orders.module.scss";

type ChannelFilter = 'ALL' | BoxOfficeOrderChannel;
type TicketAction = 'badge-and-entry' | 'reprint' | 'entry';

const KioskOrders = () => {
    const {eventId} = useParams();
    const context = useGetBoxOfficeContext();
    const {settings, setSettings} = useKioskSettings();
    const currentEvent = (context.data?.data ?? []).find((event) => String(event.id) === String(eventId));

    const [query, setQuery] = useState('');
    const [debouncedQuery] = useDebouncedValue(query.trim(), 300);
    const [channel, setChannel] = useState<ChannelFilter>('ALL');
    const [toggles, setToggles] = useState<string[]>([]);
    const [openOrderId, setOpenOrderId] = useState<string | null>(null);
    const [highlightedTicket, setHighlightedTicket] = useState<string | null>(null);
    const [busyKey, setBusyKey] = useState<string | null>(null);

    const filters = useMemo(() => ({
        query: debouncedQuery.length >= 2 ? debouncedQuery : '',
        channel: channel === 'ALL' ? null : channel,
        mine: toggles.includes('mine'),
        not_checked_in: toggles.includes('not_checked_in'),
        cancelled: toggles.includes('cancelled'),
    }), [debouncedQuery, channel, toggles]);

    const ordersQuery = useGetBoxOfficeOrders(eventId, filters);
    const orders = ordersQuery.data?.pages.flatMap((page) => page.data) ?? [];
    const total = ordersQuery.data?.pages[0]?.meta.total ?? 0;
    const detailQuery = useGetBoxOfficeOrder(eventId, openOrderId);
    const detail = detailQuery.data?.data;
    const checkIn = useCheckInBoxOfficeAttendee();

    const {printTickets, printerPromptModal} = useKioskTicketPrinter({
        eventId: eventId as string,
        printMode: settings.printOutput as KioskPrintOutput,
        skipPrint: settings.printOutput === 'none',
        zebraPrinterHost: settings.zebraPrinterHost || currentEvent?.last_printer_host || '',
        onZebraPrinterHostChange: (zebraPrinterHost) => setSettings({...settings, zebraPrinterHost}),
    });

    const formatTime = (value: string | null, withDate = false) => {
        if (!value) {
            return '';
        }
        const local = currentEvent?.timezone
            ? dayjs(utcToTz(value, currentEvent.timezone) ?? value)
            : dayjs(value);
        return withDate ? local.format('DD/MM HH:mm') : local.format('HH:mm');
    };

    const buyerName = (order: BoxOfficeOrderListItem) =>
        [order.first_name, order.last_name].filter(Boolean).join(' ') || order.phone || order.public_id;

    const ticketName = (ticket: BoxOfficeAttendeeSearchResult) =>
        [ticket.first_name, ticket.last_name].filter(Boolean).join(' ') || ticket.public_id;

    const isOpenTicket = (ticket: BoxOfficeAttendeeSearchResult) => ticket.status !== 'CANCELLED';

    const announceCheckIn = (ticket: BoxOfficeAttendeeSearchResult, result: BoxOfficeCheckInResult) => {
        if (result.status === 'CHECKED_IN') {
            showSuccess(t`${ticketName(ticket)} checked in`);
        } else if (result.status === 'ALREADY_CHECKED_IN') {
            showError(t`${ticketName(ticket)} already entered at ${formatTime(result.checked_in_at)}`);
        } else {
            showError(result.message || t`This ticket cannot be checked in.`);
        }
    };

    const runCheckIn = async (ticket: BoxOfficeAttendeeSearchResult) => {
        try {
            const response = await checkIn.mutateAsync({eventId: eventId as string, attendeePublicId: ticket.public_id});
            announceCheckIn(ticket, response.data);
        } catch {
            showError(t`Check-in failed. Please try again.`);
        }
    };

    const handleTicketAction = async (ticket: BoxOfficeAttendeeSearchResult, action: TicketAction) => {
        setBusyKey(ticket.public_id);
        try {
            if (action === 'reprint') {
                await printTickets([ticket.public_id], true);
            }
            if (action === 'badge-and-entry') {
                await printTickets([ticket.public_id]);
            }
            if (action !== 'reprint') {
                await runCheckIn(ticket);
            }
        } finally {
            setBusyKey(null);
        }
    };

    const reprintOrder = async () => {
        const ids = (detail?.tickets ?? []).filter(isOpenTicket).map((ticket) => ticket.public_id);
        if (ids.length === 0) {
            return;
        }
        setBusyKey('order');
        try {
            await printTickets(ids, true);
        } finally {
            setBusyKey(null);
        }
    };

    const checkInWholeOrder = async () => {
        const pending = (detail?.tickets ?? []).filter((ticket) => isOpenTicket(ticket) && !ticket.checked_in_at);
        setBusyKey('order');
        try {
            for (const ticket of pending) {
                await runCheckIn(ticket);
            }
        } finally {
            setBusyKey(null);
        }
    };

    const openOrder = (orderPublicId: string, ticketPublicId: string | null = null) => {
        setHighlightedTicket(ticketPublicId);
        setOpenOrderId(orderPublicId);
    };

    const handleScan = async () => {
        const scanned = query.trim();
        if (scanned.length < 2) {
            return;
        }
        try {
            const response = await boxOfficeClient.getOrders(eventId as string, {query: scanned});
            if (response.data.length !== 1) {
                return;
            }
            const order = response.data[0];
            const orderDetail = await boxOfficeClient.getOrder(eventId as string, order.public_id);
            const ticket = orderDetail.data.tickets.find((item) => item.public_id.toLowerCase() === scanned.toLowerCase());
            openOrder(order.public_id, ticket?.public_id ?? null);
            if (ticket && ticket.status === 'ACTIVE' && !ticket.checked_in_at) {
                setQuery('');
                await handleTicketAction(ticket, 'badge-and-entry');
            }
        } catch {
            showError(t`Search failed. Please try again.`);
        }
    };

    const orderStatus = (order: BoxOfficeOrderListItem) => {
        if (order.status === 'CANCELLED') {
            return <span className={`${classes.pill} ${classes.pillRefused}`}>{t`Cancelled`}</span>;
        }
        if (order.status === 'AWAITING_OFFLINE_PAYMENT') {
            return <span className={`${classes.pill} ${classes.pillWarning}`}>{t`Payment pending`}</span>;
        }
        const allIn = order.ticket_count > 0 && order.checked_in_count >= order.ticket_count;
        return (
            <span className={`${classes.pill} ${allIn ? classes.pillDone : classes.pillReady}`}>
                {t`${order.checked_in_count}/${order.ticket_count} entered`}
            </span>
        );
    };

    const ticketStatus = (ticket: BoxOfficeAttendeeSearchResult) => {
        if (ticket.status === 'CANCELLED') {
            return <span className={`${classes.pill} ${classes.pillRefused}`}>{t`Cancelled`}</span>;
        }
        if (ticket.status === 'AWAITING_PAYMENT') {
            return <span className={`${classes.pill} ${classes.pillWarning}`}>{t`Payment pending`}</span>;
        }
        if (ticket.checked_in_at) {
            return <span className={`${classes.pill} ${classes.pillDone}`}>{t`Entered at ${formatTime(ticket.checked_in_at)}`}</span>;
        }
        return <span className={`${classes.pill} ${classes.pillReady}`}>{t`Not entered yet`}</span>;
    };

    const channelLabel = (order: BoxOfficeOrderListItem) => {
        if (order.channel === 'BOX_OFFICE') {
            return <span className={`${classes.channel} ${classes.channelBoxOffice}`}>{t`Box office`}</span>;
        }
        if (order.channel === 'MANUAL') {
            return <span className={`${classes.channel} ${classes.channelOnline}`}>{t`Added manually`}</span>;
        }
        return <span className={`${classes.channel} ${classes.channelOnline}`}>{t`Online`}</span>;
    };

    const pendingCount = (detail?.tickets ?? []).filter((ticket) => isOpenTicket(ticket) && !ticket.checked_in_at).length;

    return (
        <div className={classes.page}>
            {printerPromptModal}
            <header className={classes.header}>
                <h1 className={classes.title}>{currentEvent?.title}</h1>
                <p className={classes.subtitle}>{t`Every order of the event — find a buyer, reprint tickets and record entries.`}</p>
            </header>

            <form
                className={classes.search}
                onSubmit={(event) => {
                    event.preventDefault();
                    void handleScan();
                }}
            >
                <TextInput
                    size="xl"
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.currentTarget.value)}
                    placeholder={t`Name, e-mail, phone, order or ticket number — or scan the QR code`}
                    leftSection={<IconSearch/>}
                    rightSection={ordersQuery.isFetching ? <Loader size="sm"/> : null}
                    aria-label={t`Search orders`}
                />
            </form>

            <div className={classes.filters}>
                <SegmentedControl
                    size="md"
                    value={channel}
                    onChange={(value) => setChannel(value as ChannelFilter)}
                    data={[
                        {value: 'ALL', label: t`All`},
                        {value: 'BOX_OFFICE', label: t`Box office`},
                        {value: 'ONLINE', label: t`Online`},
                    ]}
                />
                <Chip.Group multiple value={toggles} onChange={setToggles}>
                    <div className={classes.chips}>
                        <Chip size="md" value="mine">{t`My sales`}</Chip>
                        <Chip size="md" value="not_checked_in">{t`Not entered`}</Chip>
                        <Chip size="md" value="cancelled">{t`Cancelled`}</Chip>
                    </div>
                </Chip.Group>
                <span className={classes.count}>{plural(total, {one: '# order', other: '# orders'})}</span>
            </div>

            <div className={classes.list}>
                {ordersQuery.isLoading && <Loader className={classes.centered}/>}
                {!ordersQuery.isLoading && orders.length === 0 && (
                    <p className={classes.hint}>{t`No order found.`}</p>
                )}
                {orders.map((order) => (
                    <button
                        type="button"
                        key={order.public_id}
                        className={`${classes.row} ${openOrderId === order.public_id ? classes.rowActive : ''}`}
                        onClick={() => openOrder(order.public_id)}
                    >
                        <div className={classes.rowMain}>
                            <div className={classes.rowName}>{buyerName(order)}</div>
                            <div className={classes.rowMeta}>
                                {channelLabel(order)}
                                <span>{order.public_id}</span>
                                <span>{formatTime(order.created_at, true)}</span>
                                {order.agent_name && <span>{order.agent_name}</span>}
                            </div>
                        </div>
                        <div className={classes.rowSide}>
                            <div className={classes.rowAmount}>
                                {order.total_gross > 0 ? formatCurrency(order.total_gross, order.currency) : t`Free`}
                            </div>
                            <div className={classes.rowTickets}>
                                {plural(order.ticket_count, {one: '# ticket', other: '# tickets'})}
                            </div>
                            {orderStatus(order)}
                        </div>
                    </button>
                ))}
                {ordersQuery.hasNextPage && (
                    <Button
                        variant="default"
                        size="lg"
                        loading={ordersQuery.isFetchingNextPage}
                        onClick={() => void ordersQuery.fetchNextPage()}
                    >
                        {t`Load more`}
                    </Button>
                )}
            </div>

            <Drawer
                opened={!!openOrderId}
                onClose={() => {
                    setOpenOrderId(null);
                    setHighlightedTicket(null);
                }}
                position="right"
                size="xl"
                title={detail ? buyerName(detail.order) : t`Order`}
            >
                {detailQuery.isLoading && <Loader className={classes.centered}/>}
                {detail && (
                    <div className={classes.detail}>
                        <div className={classes.detailMeta}>
                            {channelLabel(detail.order)}
                            <span>{detail.order.public_id}</span>
                            <span>{formatTime(detail.order.created_at, true)}</span>
                            <span>
                                {detail.order.total_gross > 0
                                    ? formatCurrency(detail.order.total_gross, detail.order.currency)
                                    : t`Free`}
                            </span>
                            {detail.order.agent_name && <span>{t`Sold by ${detail.order.agent_name}`}</span>}
                            {detail.order.email && <span>{detail.order.email}</span>}
                            {detail.order.phone && <span>{detail.order.phone}</span>}
                        </div>

                        <div className={classes.detailActions}>
                            <Button
                                size="lg"
                                leftSection={<IconPrinter/>}
                                loading={busyKey === 'order'}
                                disabled={busyKey !== null || detail.tickets.filter(isOpenTicket).length === 0}
                                onClick={() => void reprintOrder()}
                            >
                                {t`Reprint the whole order`}
                            </Button>
                            <Button
                                size="lg"
                                variant="light"
                                leftSection={<IconUsersGroup/>}
                                disabled={busyKey !== null || pendingCount === 0}
                                onClick={() => void checkInWholeOrder()}
                            >
                                {t`Check everyone in (${pendingCount})`}
                            </Button>
                        </div>

                        <div className={classes.tickets}>
                            {detail.tickets.map((ticket) => {
                                const blocked = ticket.status === 'CANCELLED';
                                const isBusy = busyKey === ticket.public_id;
                                return (
                                    <article
                                        key={ticket.public_id}
                                        className={`${classes.ticket} ${highlightedTicket === ticket.public_id ? classes.ticketHighlighted : ''}`}
                                    >
                                        <div className={classes.ticketIdentity}>
                                            <div className={classes.ticketName}>{ticketName(ticket)}</div>
                                            <div className={classes.rowMeta}>
                                                <span>{ticket.product_title}</span>
                                                <span>{ticket.public_id}</span>
                                            </div>
                                            {ticketStatus(ticket)}
                                        </div>
                                        <div className={classes.ticketActions}>
                                            <Button
                                                size="md"
                                                variant="light"
                                                leftSection={<IconPrinter/>}
                                                disabled={blocked || busyKey !== null}
                                                loading={isBusy}
                                                onClick={() => void handleTicketAction(ticket, 'reprint')}
                                            >
                                                {t`Reprint`}
                                            </Button>
                                            {!ticket.checked_in_at && (
                                                <>
                                                    <Button
                                                        size="md"
                                                        leftSection={<IconId/>}
                                                        disabled={blocked || busyKey !== null}
                                                        onClick={() => void handleTicketAction(ticket, 'badge-and-entry')}
                                                    >
                                                        {t`Badge + entry`}
                                                    </Button>
                                                    <Button
                                                        size="md"
                                                        variant="default"
                                                        leftSection={<IconDoorEnter/>}
                                                        disabled={blocked || busyKey !== null}
                                                        onClick={() => void handleTicketAction(ticket, 'entry')}
                                                    >
                                                        {t`Entry only`}
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                        <p className={classes.note}>
                            <Trans>A reprinted ticket keeps the same QR code: only the first copy scanned at the door will be accepted.</Trans>
                        </p>
                    </div>
                )}
            </Drawer>
        </div>
    );
};

export default KioskOrders;
