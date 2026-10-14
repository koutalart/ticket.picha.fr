import {useEffect, useState} from "react";
import {NavLink, useNavigate, useParams} from "react-router";
import {ActionIcon, Skeleton} from "@mantine/core";
import {IconPlus, IconSearch, IconX} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useFilterQueryParamSync} from "../../../../hooks/useFilterQueryParamSync.ts";
import {Event, QueryFilters} from "../../../../types.ts";
import {SearchBarWrapper} from "../../../common/SearchBar";
import {Pagination} from "../../../common/Pagination";
import {NoEventsBlankSlate} from "../../../common/NoEventsBlankSlate";
import {useGetEvents} from "../../../../queries/useGetEvents.ts";
import {getEventQueryFilters} from "../../../../utilites/eventsPageFiltersHelper.ts";
import {OrganizerEventRow} from "../OrganizerDashboard/OrganizerEventRow.tsx";
import dashboardClasses from "../OrganizerDashboard/OrganizerDashboard.module.scss";
import classes from "./Events.module.scss";

const Events = () => {
    const {organizerId, eventsState} = useParams();
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const navigate = useNavigate();
    const openCreateModal = () => navigate(`/manage/new-event?organizer=${organizerId}`);
    const {data: eventsData, isFetched: isEventsFetched} = useGetEvents(
        getEventQueryFilters(searchParams) as QueryFilters
    );
    const pagination = eventsData?.meta;
    const events = eventsData?.data;
    const hasSearch = Boolean(searchParams.query);
    const [searchOpen, setSearchOpen] = useState(hasSearch);

    useEffect(() => {
        if (hasSearch) setSearchOpen(true);
    }, [hasSearch]);

    const toggleSearch = () => {
        if (searchOpen && hasSearch) {
            setSearchParams({query: '', pageNumber: 1});
        }
        setSearchOpen(!searchOpen);
    };

    const baseUrl = `/manage/organizer/${organizerId}/events`;
    const tabs = [
        {value: 'upcoming', label: t`Upcoming`},
        {value: 'ended', label: t`Ended`},
        {value: 'archived', label: t`Archived`},
    ];
    const activeTab = eventsState || 'upcoming';

    return (
        <div className={dashboardClasses.page}>
            <header className={classes.header}>
                <h1 className={classes.title}><Trans>Events</Trans></h1>
                <div className={classes.actions}>
                    <ActionIcon
                        size={44}
                        radius="md"
                        variant={searchOpen || hasSearch ? 'light' : 'subtle'}
                        color={hasSearch ? 'violet' : 'gray'}
                        onClick={toggleSearch}
                        aria-label={searchOpen ? t`Close search` : t`Search an event…`}
                        aria-expanded={searchOpen}
                    >
                        {searchOpen ? <IconX size={20}/> : <IconSearch size={20}/>}
                    </ActionIcon>
                    <ActionIcon className={classes.createMobile} size={44} radius="md" variant="filled"
                                onClick={openCreateModal} aria-label={t`Create Event`}>
                        <IconPlus size={20}/>
                    </ActionIcon>
                </div>
            </header>

            {searchOpen && (
                <div className={classes.search}>
                    <SearchBarWrapper
                        placeholder={t`Search by event name...`}
                        setSearchParams={setSearchParams}
                        searchParams={searchParams}
                        pagination={pagination}
                        autoFocus
                    />
                </div>
            )}

            <div className={dashboardClasses.tabs} role="tablist">
                {tabs.map((item) => (
                    <NavLink key={item.value} to={`${baseUrl}/${item.value}`} role="tab"
                             aria-selected={activeTab === item.value}
                             className={activeTab === item.value ? dashboardClasses.tabActive : dashboardClasses.tab}>
                        {item.label}
                    </NavLink>
                ))}
            </div>

            {(events?.length === 0 && isEventsFetched) && (
                hasSearch
                    ? <p className={classes.muted}><Trans>No event matches your search.</Trans></p>
                    : <NoEventsBlankSlate openCreateModal={openCreateModal} eventsState={eventsState}/>
            )}

            {!isEventsFetched && (
                <div className={dashboardClasses.list}>
                    {[...Array(3)].map((_, index) => <Skeleton key={index} height={110} radius="md"/>)}
                </div>
            )}

            {events && events.length > 0 && (
                <div className={dashboardClasses.list}>
                    {events.map((event: Event) => <OrganizerEventRow key={event.id} event={event}/>)}
                </div>
            )}

            {isEventsFetched && events && events.length > 0 && Number(pagination?.last_page) > 1 && (
                <Pagination value={searchParams.pageNumber}
                            onChange={(value) => setSearchParams({pageNumber: value})}
                            total={Number(pagination?.last_page)}
                />
            )}
        </div>
    );
}

export default Events;
