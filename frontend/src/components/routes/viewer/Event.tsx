import {useState} from "react";
import {Link, useParams} from "react-router";
import {Badge, Button, Loader, Pagination, Table, Tabs, TextInput} from "@mantine/core";
import {useDebouncedValue} from "@mantine/hooks";
import {IconArrowLeft, IconDownload, IconSearch} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetViewerEvents} from "../../../queries/useGetViewerEvents.ts";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {useGetAttendees} from "../../../queries/useGetAttendees.ts";
import {useGetEventOrders} from "../../../queries/useGetEventOrders.ts";
import {attendeesClient} from "../../../api/attendee.client.ts";
import {orderClient} from "../../../api/order.client.ts";
import {downloadBinary} from "../../../utilites/download.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {formatCurrency} from "../../../utilites/currency.ts";
import {prettyDate} from "../../../utilites/dates.ts";
import {formatNumber} from "../../../utilites/helpers.ts";
import {LogoutButton} from "./LogoutButton.tsx";
import classes from "./Viewer.module.scss";

const PER_PAGE = 50;

const useSearchPage = () => {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [query] = useDebouncedValue(search, 300);
    return {
        search,
        page,
        setPage,
        setSearch: (value: string) => {
            setSearch(value);
            setPage(1);
        },
        filters: {pageNumber: page, perPage: PER_PAGE, query: query || undefined},
    };
};

const SearchInput = ({value, onChange}: { value: string, onChange: (value: string) => void }) => (
    <TextInput
        className={classes.search}
        value={value}
        onChange={(event) => onChange(event.currentTarget.value)}
        placeholder={t`Search by name or email`}
        leftSection={<IconSearch size={16}/>}
    />
);

const ExportButton = ({label, filename, run}: { label: string, filename: string, run: () => Promise<Blob> }) => {
    const [busy, setBusy] = useState(false);
    const onClick = async () => {
        setBusy(true);
        try {
            downloadBinary(await run(), filename);
            showSuccess(t`The file has been downloaded.`);
        } catch {
            showError(t`The export failed. Please try again.`);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Button leftSection={<IconDownload size={16}/>} loading={busy} onClick={onClick} variant="light">
            {label}
        </Button>
    );
};

const AttendeesTab = ({eventId, timezone}: { eventId: string, timezone: string }) => {
    const state = useSearchPage();
    const {data, isLoading} = useGetAttendees(eventId, state.filters);
    const rows = data?.data ?? [];

    return (
        <>
            <div className={classes.toolbar}>
                <SearchInput value={state.search} onChange={state.setSearch}/>
                <ExportButton
                    label={t`Export attendees`}
                    filename="participants.xlsx"
                    run={() => attendeesClient.export(eventId)}
                />
            </div>
            {isLoading ? <div className={classes.empty}><Loader/></div> : (
                <div className={classes.tableWrap}>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Name`}</Table.Th>
                                <Table.Th>{t`Email`}</Table.Th>
                                <Table.Th>{t`Phone`}</Table.Th>
                                <Table.Th>{t`Status`}</Table.Th>
                                <Table.Th>{t`Checked in`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {rows.map(attendee => (
                                <Table.Tr key={attendee.id}>
                                    <Table.Td>{`${attendee.first_name} ${attendee.last_name ?? ''}`.trim()}</Table.Td>
                                    <Table.Td>{attendee.email || '-'}</Table.Td>
                                    <Table.Td>{attendee.phone || '-'}</Table.Td>
                                    <Table.Td>
                                        <Badge variant="light" color={attendee.status === 'ACTIVE' ? 'green' : 'gray'}>
                                            {attendee.status === 'ACTIVE' ? t`Active` : attendee.status === 'CANCELLED' ? t`Cancelled` : t`Awaiting payment`}
                                        </Badge>
                                    </Table.Td>
                                    <Table.Td>{attendee.checked_in_at ? prettyDate(attendee.checked_in_at, timezone) : '-'}</Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                    {rows.length === 0 && <p className={classes.empty}>{t`No attendees found.`}</p>}
                </div>
            )}
            {(data?.meta?.last_page ?? 1) > 1 && (
                <div className={classes.pagination}>
                    <Pagination value={state.page} onChange={state.setPage} total={data?.meta?.last_page ?? 1}/>
                </div>
            )}
        </>
    );
};

const OrdersTab = ({eventId, timezone}: { eventId: string, timezone: string }) => {
    const state = useSearchPage();
    const {data, isLoading} = useGetEventOrders(eventId, state.filters);
    const rows = data?.data ?? [];

    return (
        <>
            <div className={classes.toolbar}>
                <SearchInput value={state.search} onChange={state.setSearch}/>
                <ExportButton
                    label={t`Export orders`}
                    filename="commandes.xlsx"
                    run={() => orderClient.exportOrders(eventId)}
                />
            </div>
            {isLoading ? <div className={classes.empty}><Loader/></div> : (
                <div className={classes.tableWrap}>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Reference`}</Table.Th>
                                <Table.Th>{t`Name`}</Table.Th>
                                <Table.Th>{t`Email`}</Table.Th>
                                <Table.Th>{t`Total`}</Table.Th>
                                <Table.Th>{t`Date`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {rows.map(order => (
                                <Table.Tr key={order.id}>
                                    <Table.Td>{order.public_id}</Table.Td>
                                    <Table.Td>{`${order.first_name} ${order.last_name ?? ''}`.trim()}</Table.Td>
                                    <Table.Td>{order.email}</Table.Td>
                                    <Table.Td>{formatCurrency(order.total_gross, order.currency)}</Table.Td>
                                    <Table.Td>{prettyDate(order.created_at, timezone)}</Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                    {rows.length === 0 && <p className={classes.empty}>{t`No orders found.`}</p>}
                </div>
            )}
            {(data?.meta?.last_page ?? 1) > 1 && (
                <div className={classes.pagination}>
                    <Pagination value={state.page} onChange={state.setPage} total={data?.meta?.last_page ?? 1}/>
                </div>
            )}
        </>
    );
};

const ViewerEvent = () => {
    const {eventId = ''} = useParams();
    const {data: events} = useGetViewerEvents();
    const {data: stats} = useGetEventStats(eventId);
    const event = events?.find(candidate => String(candidate.id) === eventId);
    const timezone = event?.timezone || 'UTC';

    const statCards = [
        {label: t`Registered attendees`, value: stats?.total_attendees_registered},
        {label: t`Orders`, value: stats?.total_orders},
        {label: t`Checked in`, value: stats?.check_in_stats?.total_checked_in_attendees},
        {label: t`Page views`, value: stats?.total_views},
    ];

    return (
        <div className={classes.page}>
            <div className={classes.header}>
                <div>
                    {(events?.length ?? 0) > 1 && (
                        <Button component={Link} to="/lecture" variant="subtle" leftSection={<IconArrowLeft size={16}/>} px={0}>
                            {t`My events`}
                        </Button>
                    )}
                    <h1 className={classes.title}>{event?.title ?? t`Event`}</h1>
                    <p className={classes.readOnly}>{t`Read-only access`}</p>
                </div>
                <LogoutButton/>
            </div>

            <div className={classes.stats}>
                {statCards.map(card => (
                    <div key={card.label} className={classes.stat}>
                        <div className={classes.statValue}>{card.value === undefined ? '-' : formatNumber(card.value)}</div>
                        <div className={classes.statLabel}>{card.label}</div>
                    </div>
                ))}
            </div>

            <Tabs defaultValue="attendees" keepMounted={false}>
                <Tabs.List>
                    <Tabs.Tab value="attendees">{t`Attendees`}</Tabs.Tab>
                    <Tabs.Tab value="orders">{t`Orders`}</Tabs.Tab>
                </Tabs.List>
                <Tabs.Panel value="attendees">
                    <AttendeesTab eventId={eventId} timezone={timezone}/>
                </Tabs.Panel>
                <Tabs.Panel value="orders">
                    <OrdersTab eventId={eventId} timezone={timezone}/>
                </Tabs.Panel>
            </Tabs>
        </div>
    );
};

export default ViewerEvent;
