import {useEffect, useState} from 'react';
import {NavLink, useNavigate, useParams} from "react-router";
import {Button, Menu, Skeleton, TextInput, UnstyledButton} from '@mantine/core';
import {useDebouncedValue} from "@mantine/hooks";
import {IconChevronDown, IconChevronRight, IconPlus, IconSearch} from '@tabler/icons-react';
import {t, Trans} from '@lingui/macro';

import {useGetOrganizerStats} from "../../../../queries/useGetOrganizerStats.ts";
import {useGetEvents} from "../../../../queries/useGetEvents.ts";
import {useGetOrganizerOrders} from "../../../../queries/useGetOrganizerOrders.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {getEventQueryFiltersWithParams} from "../../../../utilites/eventsPageFiltersHelper.ts";
import {currenciesMap} from "../../../../../data/currencies.ts";
import {Event, EventStatus, Order, QueryFilterOperator, QueryFilters} from '../../../../types';
import {OrganizerEventRow} from "./OrganizerEventRow.tsx";
import classes from './OrganizerDashboard.module.scss';

type EventsTab = 'all' | 'upcoming' | 'ended' | 'drafts';

const eventFilters = (tab: EventsTab, organizerId: string | undefined, query: string): QueryFilters => {
    const base: QueryFilters = {perPage: 20, query: query || undefined};

    if (tab === 'upcoming' || tab === 'ended') {
        return getEventQueryFiltersWithParams(base, tab, organizerId) as QueryFilters;
    }

    return {
        ...base,
        filterFields: {
            organizer_id: {operator: QueryFilterOperator.Equals, value: organizerId},
            status: tab === 'drafts'
                ? {operator: QueryFilterOperator.Equals, value: EventStatus.DRAFT}
                : {operator: QueryFilterOperator.NotEquals, value: EventStatus.ARCHIVED},
        },
    } as QueryFilters;
};

export const OrganizerDashboard = () => {
    const {organizerId} = useParams<{ organizerId: string }>();
    const navigate = useNavigate();
    const {data: organizer} = useGetOrganizer(organizerId);
    const createEvent = () => navigate(`/manage/new-event?organizer=${organizerId}`);

    const [currency, setCurrency] = useState<string>(organizer?.currency || 'EUR');
    useEffect(() => {
        if (organizer?.currency) setCurrency(organizer.currency);
    }, [organizer?.currency]);

    const [tab, setTab] = useState<EventsTab>('all');
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search.trim(), 300);

    const statsQuery = useGetOrganizerStats(organizerId, currency);
    const stats = statsQuery.data;
    const currencies = currenciesMap.filter((item) => stats?.all_organizers_currencies?.includes(item.value));

    const eventsQuery = useGetEvents(eventFilters(tab, organizerId, debouncedSearch));
    const events = eventsQuery.data?.data ?? [];

    const ordersQuery = useGetOrganizerOrders(organizerId, {perPage: 6, sortBy: 'created_at', sortDirection: 'desc'});
    const orders = ordersQuery.data?.data ?? [];

    const tabs: { value: EventsTab, label: string }[] = [
        {value: 'all', label: t({message: 'All', context: 'events tab'})},
        {value: 'upcoming', label: t`Upcoming`},
        {value: 'ended', label: t({message: 'Past', context: 'events tab'})},
        {value: 'drafts', label: t`Drafts`},
    ];

    const statItems = [
        {label: t`Gross sales`, value: stats ? formatCurrency(stats.total_gross_sales, currency) : null},
        {label: t`Tickets sold`, value: stats ? formatNumber(stats.total_products_sold) : null},
        {label: t`Orders`, value: stats ? formatNumber(stats.total_orders) : null},
    ];

    const searchInput = (className: string) => (
        <TextInput
            className={className}
            classNames={{input: classes.searchInput}}
            leftSection={<IconSearch size={18}/>}
            placeholder={t`Search an event…`}
            value={search}
            radius="md"
            size="md"
            onChange={(event) => setSearch(event.currentTarget.value)}
        />
    );

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <span className={classes.kicker}>{organizer?.name ?? <Trans>Your organization</Trans>}</span>
                <h1 className={classes.title}>{t({message: 'Overview', context: 'organizer home'})}</h1>
            </header>

            <section className={classes.stats}>
                {statItems.map((item) => (
                    <div key={item.label} className={classes.stat}>
                        <span className={classes.statLabel}>{item.label}</span>
                        {item.value === null
                            ? <Skeleton height={36} width={90} radius="sm"/>
                            : <span className={classes.statValue}>{item.value}</span>}
                    </div>
                ))}
                {currencies.length > 1 && (
                    <Menu position="bottom-end" withinPortal>
                        <Menu.Target>
                            <UnstyledButton className={classes.currencyButton}>
                                {currency} <IconChevronDown size={14}/>
                            </UnstyledButton>
                        </Menu.Target>
                        <Menu.Dropdown>
                            {currencies.map((item) => (
                                <Menu.Item key={item.value} onClick={() => setCurrency(item.value)}>
                                    {item.value} · {item.label}
                                </Menu.Item>
                            ))}
                        </Menu.Dropdown>
                    </Menu>
                )}
            </section>

            <section className={classes.section}>
                <div className={classes.sectionHead}>
                    <h2 className={classes.sectionTitle}><Trans>Your events</Trans></h2>
                    {searchInput(classes.searchDesktop)}
                    <Button className={classes.createMobile} radius="md" leftSection={<IconPlus size={18}/>}
                            onClick={createEvent}>
                        <Trans>Create</Trans>
                    </Button>
                </div>

                <div className={classes.tabs} role="tablist">
                    {tabs.map((item) => (
                        <button key={item.value} type="button" role="tab" aria-selected={tab === item.value}
                                className={tab === item.value ? classes.tabActive : classes.tab}
                                onClick={() => setTab(item.value)}>
                            {item.label}
                        </button>
                    ))}
                </div>

                {searchInput(classes.searchMobile)}

                {eventsQuery.isLoading && (
                    <div className={classes.list}>
                        {[...Array(2)].map((_, index) => <Skeleton key={index} height={130} radius="md"/>)}
                    </div>
                )}

                {!eventsQuery.isLoading && events.length > 0 && (
                    <div className={classes.list}>
                        {events.map((event: Event) => <OrganizerEventRow key={event.id} event={event}/>)}
                    </div>
                )}

                {!eventsQuery.isLoading && events.length === 0 && (
                    <div className={classes.empty}>
                        <p>
                            {debouncedSearch
                                ? <Trans>No event matches your search.</Trans>
                                : <Trans>No events here yet.</Trans>}
                        </p>
                        {!debouncedSearch && (
                            <Button variant="light" radius="md" leftSection={<IconPlus size={16}/>} onClick={createEvent}>
                                <Trans>Create Event</Trans>
                            </Button>
                        )}
                    </div>
                )}
            </section>

            <section className={classes.section}>
                <div className={classes.sectionHead}>
                    <h2 className={classes.sectionTitle}><Trans>Latest orders</Trans></h2>
                </div>

                {ordersQuery.isLoading && <Skeleton height={64} radius="md"/>}

                {!ordersQuery.isLoading && orders.length === 0 && (
                    <p className={classes.muted}><Trans>No orders yet.</Trans></p>
                )}

                {orders.length > 0 && (
                    <div className={classes.orders}>
                        {orders.map((order: Order) => (
                            <NavLink key={order.id} to={`/manage/event/${order.event_id}/orders#order-${order.id}`}
                                     className={classes.orderRow}>
                                <div className={classes.orderWho}>
                                    <span className={classes.orderName}>{order.first_name} {order.last_name}</span>
                                    <span className={classes.orderMeta}>#{order.public_id} · {relativeDate(order.created_at)}</span>
                                </div>
                                <span className={classes.orderAmount}>{formatCurrency(order.total_gross, order.currency)}</span>
                                <IconChevronRight size={18} className={classes.orderArrow}/>
                            </NavLink>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
};

export default OrganizerDashboard;
