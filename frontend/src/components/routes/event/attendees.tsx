import {useState} from "react";
import {useParams} from "react-router";
import {Button, Menu, Skeleton} from "@mantine/core";
import {useDisclosure} from "@mantine/hooks";
import {IconChevronDown, IconDownload, IconListCheck, IconPlus, IconUsers} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useGetAttendees} from "../../../queries/useGetAttendees.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetEventStats} from "../../../queries/useGetEventStats.ts";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync.ts";
import {SearchBarWrapper} from "../../common/SearchBar";
import {Pagination} from "../../common/Pagination";
import {NoResultsSplash} from "../../common/NoResultsSplash";
import {CreateAttendeeModal} from "../../modals/CreateAttendeeModal";
import {IdParam, ProductType, QueryFilterOperator, QueryFilters} from "../../../types.ts";
import {attendeesClient} from "../../../api/attendee.client.ts";
import {downloadBinary} from "../../../utilites/download.ts";
import {withLoadingNotification} from "../../../utilites/withLoadingNotification.tsx";
import {formatNumber, getProductsFromEvent} from "../../../utilites/helpers.ts";
import {AttendeeList} from "./AttendeesPage/AttendeeList.tsx";
import classes from "./AttendeesPage/AttendeesPage.module.scss";

type StatusTab = 'all' | 'ACTIVE' | 'AWAITING_PAYMENT' | 'CANCELLED';

const countQuery = (status: string): QueryFilters => ({
    perPage: 1,
    filterFields: {status: {operator: QueryFilterOperator.Equals, value: status}},
} as QueryFilters);

const Attendees = () => {
    const {eventId} = useParams();
    const {data: event} = useGetEvent(eventId);
    const {data: stats} = useGetEventStats(eventId, 'event');
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const attendeesQuery = useGetAttendees(eventId, searchParams as QueryFilters);
    const attendees = attendeesQuery.data?.data;
    const pagination = attendeesQuery.data?.meta;
    const [createOpen, create] = useDisclosure(false);
    const [exporting, setExporting] = useState(false);

    const activeCount = useGetAttendees(eventId, countQuery('ACTIVE')).data?.meta?.total;
    const awaitingCount = useGetAttendees(eventId, countQuery('AWAITING_PAYMENT')).data?.meta?.total;
    const cancelledCount = useGetAttendees(eventId, countQuery('CANCELLED')).data?.meta?.total;

    const tickets = (getProductsFromEvent(event) ?? []).filter((product) => product.product_type === ProductType.Ticket);
    const fields = (searchParams.filterFields ?? {}) as Record<string, { value?: unknown }>;
    const statusTab = (fields.status?.value as StatusTab) ?? 'all';
    const ticketTab = fields.product_id?.value ? String(fields.product_id.value) : null;

    const applyFilters = (status: StatusTab, ticketId: string | null) => setSearchParams({
        ...searchParams,
        pageNumber: 1,
        filterFields: {
            ...(status !== 'all' ? {status: {operator: QueryFilterOperator.Equals, value: status}} : {}),
            ...(ticketId ? {product_id: {operator: QueryFilterOperator.Equals, value: ticketId}} : {}),
        },
    } as QueryFilters, true);

    const exportAttendees = (id: IdParam) => withLoadingNotification(async () => {
        setExporting(true);
        const blob = await attendeesClient.export(id);
        downloadBinary(blob, 'participants.xlsx');
    }, {
        loading: {title: t`Exporting Attendees`, message: t`Please wait while we prepare your attendees for export...`},
        success: {title: t`Attendees Exported`, message: t`Your attendees have been exported successfully.`, onRun: () => setExporting(false)},
        error: {title: t`Failed to export attendees`, message: t`Please try again.`, onRun: () => setExporting(false)},
    });

    const exportEntryControlList = (id: IdParam) => withLoadingNotification(async () => {
        setExporting(true);
        const blob = await attendeesClient.exportEntryControlList(id);
        downloadBinary(blob, 'liste-controle-entrees.xlsx');
    }, {
        loading: {title: t`Exporting the entry control list`, message: t`Please wait while we prepare the file...`},
        success: {title: t`Entry control list exported`, message: t`The file has been downloaded.`, onRun: () => setExporting(false)},
        error: {title: t`Failed to export the entry control list`, message: t`Please try again.`, onRun: () => setExporting(false)},
    });

    const entered = stats?.check_in_stats?.total_checked_in_attendees ?? 0;
    const valid = activeCount ?? stats?.check_in_stats?.total_attendees ?? 0;
    const percent = valid > 0 ? Math.round(entered / valid * 100) : 0;

    const statusTabs: { value: StatusTab, label: string, count?: number }[] = [
        {value: 'all', label: t({message: 'All', context: 'attendees filter'})},
        {value: 'ACTIVE', label: t({message: 'Valid', context: 'attendees filter'}), count: activeCount},
        {value: 'AWAITING_PAYMENT', label: t({message: 'Awaiting payment', context: 'order status'}), count: awaitingCount},
        {value: 'CANCELLED', label: t({message: 'Cancelled', context: 'attendees filter'}), count: cancelledCount},
    ];

    const filtered = Boolean(searchParams.query) || statusTab !== 'all' || ticketTab !== null;

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <div>
                    <h1 className={classes.title}><Trans>Attendees</Trans></h1>
                    <p className={classes.subtitle}><Trans>Everyone who has a ticket for this event.</Trans></p>
                </div>
                <div className={classes.headerActions}>
                    <Menu position="bottom-end" radius="md" shadow="md" width={260}>
                        <Menu.Target>
                            <Button variant="default" radius="md" leftSection={<IconDownload size={16}/>}
                                    rightSection={<IconChevronDown size={14}/>} loading={exporting}>
                                <Trans>Export</Trans>
                            </Button>
                        </Menu.Target>
                        <Menu.Dropdown>
                            <Menu.Item leftSection={<IconUsers size={16}/>} onClick={() => exportAttendees(eventId as IdParam)}>
                                <Trans>Attendee list</Trans>
                            </Menu.Item>
                            <Menu.Item leftSection={<IconListCheck size={16}/>} onClick={() => exportEntryControlList(eventId as IdParam)}>
                                <Trans>Entry control list</Trans>
                            </Menu.Item>
                        </Menu.Dropdown>
                    </Menu>
                    <Button radius="md" leftSection={<IconPlus size={16}/>} onClick={create.open}>
                        <Trans>Add an attendee</Trans>
                    </Button>
                </div>
            </header>

            <section className={classes.entries}>
                {stats ? (
                    <>
                        <p className={classes.entriesText}>
                            <strong>{formatNumber(entered)}</strong>{' '}
                            <Trans>entered out of {formatNumber(valid)}</Trans>
                            <span className={classes.entriesPercent}>{percent} %</span>
                        </p>
                        <div className={classes.bar}><div style={{width: `${percent}%`}}/></div>
                        <p className={classes.entriesRemaining}>
                            <Trans>{formatNumber(Math.max(0, valid - entered))} still to come</Trans>
                        </p>
                    </>
                ) : <Skeleton height={70} radius="md"/>}
            </section>

            <div className={classes.filters}>
                <div className={classes.chips}>
                    {statusTabs.map((tab) => (
                        <button key={tab.value} type="button"
                                className={statusTab === tab.value ? classes.chipActive : classes.chip}
                                onClick={() => applyFilters(tab.value, ticketTab)}>
                            {tab.label}
                            {tab.count !== undefined && tab.count > 0 && <span className={classes.chipCount}>{tab.count}</span>}
                        </button>
                    ))}
                    {tickets.length > 1 && <span className={classes.chipDivider}/>}
                    {tickets.length > 1 && tickets.map((ticket) => (
                        <button key={ticket.id} type="button"
                                className={ticketTab === String(ticket.id) ? classes.chipActive : classes.chip}
                                onClick={() => applyFilters(statusTab, ticketTab === String(ticket.id) ? null : String(ticket.id))}>
                            {ticket.title}
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

            {!attendees && (
                <div className={classes.skeletons}>
                    {[...Array(4)].map((_, index) => <Skeleton key={index} height={72} radius="md"/>)}
                </div>
            )}

            {attendees && event && attendees.length > 0 && <AttendeeList attendees={attendees} event={event}/>}

            {attendees && attendees.length === 0 && (
                <NoResultsSplash
                    imageHref={'/blank-slate/attendees.svg'}
                    heading={filtered ? t`No attendee matches these filters` : t`No Attendees to show`}
                    subHeading={(
                        <>
                            <p>{t`Your attendees will appear here once they have registered for your event. You can also manually add attendees.`}</p>
                            {!filtered && (
                                <Button radius="md" leftSection={<IconPlus size={16}/>} onClick={create.open}>
                                    <Trans>Add an attendee</Trans>
                                </Button>
                            )}
                        </>
                    )}
                />
            )}

            {!!attendees?.length && Number(pagination?.last_page) > 1 && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}

            {createOpen && <CreateAttendeeModal onClose={create.close} isOpen={createOpen}/>}
        </div>
    );
};

export default Attendees;
