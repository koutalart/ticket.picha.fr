import {useMemo, useState} from "react";
import {Link, useParams, useSearchParams} from "react-router";
import {t, Trans} from "@lingui/macro";
import {Anchor, Badge, Button, Checkbox, Group, Menu, Table, Tabs, TextInput} from "@mantine/core";
import {useDebouncedCallback, useLocalStorage} from "@mantine/hooks";
import {
    IconCalendarEvent,
    IconChevronDown,
    IconColumns,
    IconDownload,
    IconFilter,
    IconPhone,
    IconSearch,
    IconTag,
    IconTicket,
    IconX,
} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {Pagination} from "../../../common/Pagination";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {NoResultsSplash} from "../../../common/NoResultsSplash";
import {useGetContacts} from "../../../../queries/useGetContacts.ts";
import {useGetContactsSummary} from "../../../../queries/useGetContactsSummary.ts";
import {useGetContactTags} from "../../../../queries/useGetContactTags.ts";
import {useGetOrganizerEvents} from "../../../../queries/useGetOrganizerEvents.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useAssignContactTag} from "../../../../mutations/useContactTags.ts";
import {Contact, contactClient, ContactFilters} from "../../../../api/contact.client.ts";
import {IdParam} from "../../../../types.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../utilites/dates.ts";
import {formatPhone} from "../../../../utilites/phone.ts";
import {downloadBinary} from "../../../../utilites/download.ts";
import {withLoadingNotification} from "../../../../utilites/withLoadingNotification.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {CreateContactModal} from "./CreateContactModal.tsx";
import {ImportContactsModal} from "./ImportContactsModal.tsx";
import classes from "./Contacts.module.scss";

type ColumnKey = 'email' | 'phone' | 'events' | 'attended' | 'last_order' | 'total' | 'email_consent' | 'sms_consent' | 'tags' | 'source';

const DEFAULT_COLUMNS: ColumnKey[] = ['email', 'phone', 'events', 'last_order', 'total', 'email_consent', 'tags'];

type Tab = 'all' | 'email_consent' | 'sms_consent';

const Contacts = () => {
    const {organizerId} = useParams();
    const [searchParams, setSearchParams] = useSearchParams();
    const [searchText, setSearchText] = useState(searchParams.get('query') ?? '');
    const [selectedIds, setSelectedIds] = useState<IdParam[]>([]);
    const [tagName, setTagName] = useState('');
    const [modal, setModal] = useState<'create' | 'import' | null>(null);
    const [visibleColumns, setVisibleColumns] = useLocalStorage<ColumnKey[]>({
        key: 'picha-contacts-columns',
        defaultValue: DEFAULT_COLUMNS,
    });

    const filters: ContactFilters = useMemo(() => ({
        query: searchParams.get('query') ?? undefined,
        event_id: searchParams.get('event_id') ?? undefined,
        tag_id: searchParams.get('tag_id') ?? undefined,
        email_consent: searchParams.get('email_consent') === '1',
        sms_consent: searchParams.get('sms_consent') === '1',
        attended: searchParams.get('attended') === '1',
        has_phone: searchParams.get('has_phone') === '1',
        page: Number(searchParams.get('page') ?? 1),
        per_page: 25,
    }), [searchParams]);

    const {data: organizer} = useGetOrganizer(organizerId);
    const contactsQuery = useGetContacts(organizerId, filters);
    const {data: summary} = useGetContactsSummary(organizerId, filters);
    const {data: allSummary} = useGetContactsSummary(organizerId, {});
    const {data: tags} = useGetContactTags(organizerId);
    const {data: events} = useGetOrganizerEvents(organizerId, {pageNumber: 1, perPage: 100});
    const assignTagMutation = useAssignContactTag();

    const contacts = contactsQuery.data?.data ?? [];
    const pagination = contactsQuery.data?.meta;
    const currency = organizer?.currency ?? 'EUR';
    const timezone = organizer?.timezone ?? 'UTC';
    const eventOptions = events?.data ?? [];

    const updateParams = (changes: Record<string, string | null | undefined>) => {
        const next = new URLSearchParams(searchParams);
        Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key));
        next.delete('page');
        setSelectedIds([]);
        setSearchParams(next, {replace: true});
    };

    const debouncedSearch = useDebouncedCallback((value: string) => updateParams({query: value.trim()}), 350);

    const activeTab: Tab = filters.email_consent ? 'email_consent' : filters.sms_consent ? 'sms_consent' : 'all';

    const activeFilters = [
        filters.event_id && {
            key: 'event_id',
            label: t`Event: ${eventOptions.find(event => String(event.id) === filters.event_id)?.title ?? '…'}`,
        },
        filters.tag_id && {
            key: 'tag_id',
            label: t`Tag: ${tags?.find(tag => String(tag.id) === filters.tag_id)?.name ?? '…'}`,
        },
        filters.attended && {key: 'attended', label: t`Came (scanned)`},
        filters.has_phone && {key: 'has_phone', label: t`With a phone number`},
    ].filter(Boolean) as { key: string, label: string }[];

    const columnLabels: Record<ColumnKey, string> = {
        email: t`Email`,
        phone: t`Phone`,
        events: t`Events`,
        attended: t`Came`,
        last_order: t`Last purchase`,
        total: t`Total`,
        email_consent: t`Emails`,
        sms_consent: t`SMS`,
        tags: t`Tags`,
        source: t`Source`,
    };

    const sourceLabels: Record<Contact['source'], string> = {
        ORDER: t`Purchase`,
        ATTENDEE: t`Ticket received`,
        IMPORT: t`Import`,
        MANUAL: t`Added by hand`,
    };

    const formatDay = (date?: string | null) => date ? formatDateWithLocale(date, 'shortDate', timezone) : '—';
    const consentBadge = (active: boolean) => (
        <Badge variant="light" color={active ? 'green' : 'gray'} tt="none">{active ? t`yes` : t`no`}</Badge>
    );

    const cell = (contact: Contact, column: ColumnKey) => {
        switch (column) {
            case 'email':
                return <span className={classes.email}>{contact.email ?? '—'}</span>;
            case 'phone':
                return <span className={classes.numeric}>{formatPhone(contact.phone)}</span>;
            case 'events':
                return <span className={classes.numeric}>{contact.events_count}</span>;
            case 'attended':
                return <span className={classes.numeric}>{contact.attended_events_count}</span>;
            case 'last_order':
                return <span className={classes.numeric}>{formatDay(contact.last_order_at)}</span>;
            case 'total':
                return <span className={classes.numeric}>{formatCurrency(contact.total_spent, currency)}</span>;
            case 'email_consent':
                return consentBadge(contact.email_consent);
            case 'sms_consent':
                return consentBadge(contact.sms_consent);
            case 'tags':
                return (
                    <div className={classes.tags}>
                        {contact.tags.map(tag => <Badge key={tag.id} variant="light" size="sm" tt="none">{tag.name}</Badge>)}
                    </div>
                );
            case 'source':
                return sourceLabels[contact.source];
        }
    };

    const columns = (Object.keys(columnLabels) as ColumnKey[]).filter(column => visibleColumns.includes(column));
    const allSelected = contacts.length > 0 && contacts.every(contact => selectedIds.includes(contact.id));

    const handleAssignTag = () => {
        if (!tagName.trim() || selectedIds.length === 0) {
            return;
        }

        assignTagMutation.mutate({organizerId: organizerId as IdParam, name: tagName.trim(), contactIds: selectedIds}, {
            onSuccess: () => {
                showSuccess(t`Tag added`);
                setTagName('');
                setSelectedIds([]);
            },
            onError: () => showError(t`The tag could not be added. Please try again.`),
        });
    };

    const handleExport = () => withLoadingNotification(async () => {
        const blob = await contactClient.export(organizerId as IdParam, filters);
        downloadBinary(blob, 'contacts.csv');
    }, {
        loading: {title: t`Exporting contacts`, message: t`Please wait while we prepare your contacts.`},
        success: {title: t`Contacts exported`, message: t`Your contacts have been exported.`},
        error: {title: t`Export failed`, message: t`Please try again.`},
    });

    return (
        <PageBody>
            <div className={classes.header}>
                <div className={classes.titleGroup}>
                    <h1 className={classes.title}>{t`Contacts`}</h1>
                    <span className={classes.titleCount}>{allSummary?.total ?? ''}</span>
                </div>
                <Group gap="sm">
                    <Button variant="default" onClick={() => setModal('create')}>{t`Create a contact`}</Button>
                    <Button color="dark" onClick={() => setModal('import')}>{t`Import contacts`}</Button>
                </Group>
            </div>

            <Tabs
                value={activeTab}
                onChange={(value) => updateParams({
                    email_consent: value === 'email_consent' ? '1' : null,
                    sms_consent: value === 'sms_consent' ? '1' : null,
                })}
                mb="md"
            >
                <Tabs.List>
                    <Tabs.Tab value="all">{t`All contacts`}</Tabs.Tab>
                    <Tabs.Tab value="email_consent">{t`Accept emails`}</Tabs.Tab>
                    <Tabs.Tab value="sms_consent">{t`Accept SMS`}</Tabs.Tab>
                </Tabs.List>
            </Tabs>

            <div className={classes.filters}>
                <Menu shadow="md" width={280} position="bottom-start">
                    <Menu.Target>
                        <Button variant="default" rightSection={<IconChevronDown size={14}/>}>
                            {t`Load an event or a tag`}
                        </Button>
                    </Menu.Target>
                    <Menu.Dropdown className={classes.menuScroll}>
                        <Menu.Label>{t`Came to an event`}</Menu.Label>
                        {eventOptions.length === 0 && <Menu.Item disabled>{t`No events`}</Menu.Item>}
                        {eventOptions.map(event => (
                            <Menu.Item key={event.id} leftSection={<IconCalendarEvent size={14}/>}
                                       onClick={() => updateParams({event_id: String(event.id)})}>
                                {event.title}
                            </Menu.Item>
                        ))}
                        <Menu.Divider/>
                        <Menu.Label>{t`Tags`}</Menu.Label>
                        {(tags ?? []).length === 0 && <Menu.Item disabled>{t`No tags yet`}</Menu.Item>}
                        {(tags ?? []).map(tag => (
                            <Menu.Item key={tag.id} leftSection={<IconTag size={14}/>}
                                       rightSection={<span className={classes.menuCount}>{tag.contacts_count}</span>}
                                       onClick={() => updateParams({tag_id: String(tag.id)})}>
                                {tag.name}
                            </Menu.Item>
                        ))}
                    </Menu.Dropdown>
                </Menu>

                <Menu shadow="md" width={240} position="bottom-start">
                    <Menu.Target>
                        <Button variant="default" leftSection={<IconFilter size={14}/>} rightSection={<IconChevronDown size={14}/>}>
                            {t`Add filter`}
                        </Button>
                    </Menu.Target>
                    <Menu.Dropdown>
                        <Menu.Item leftSection={<IconTicket size={14}/>} onClick={() => updateParams({attended: '1'})}>
                            {t`Came (scanned)`}
                        </Menu.Item>
                        <Menu.Item leftSection={<IconPhone size={14}/>} onClick={() => updateParams({has_phone: '1'})}>
                            {t`With a phone number`}
                        </Menu.Item>
                    </Menu.Dropdown>
                </Menu>

                {activeFilters.map(filter => (
                    <Badge key={filter.key} size="lg" variant="light" tt="none" className={classes.filterPill}
                           rightSection={
                               <IconX size={12} className={classes.pillClose} aria-label={t`Remove filter`}
                                      onClick={() => updateParams({[filter.key]: null})}/>
                           }>
                        {filter.label}
                    </Badge>
                ))}
            </div>

            <div className={classes.countRow}>
                <span className={classes.countText}>
                    <Trans>{summary?.total ?? 0} contacts</Trans>
                    {summary && (
                        <span className={classes.countDetail}>
                            {' · '}{t`${summary.email_consent} accept emails`}
                            {' · '}{t`${summary.repeat_customers} came to 2 events or more`}
                            {' · '}{formatCurrency(summary.total_spent, currency)}
                        </span>
                    )}
                </span>
                <Group gap="sm" className={classes.countActions}>
                    <Menu shadow="md" width={220} closeOnItemClick={false} position="bottom-end">
                        <Menu.Target>
                            <Button variant="subtle" leftSection={<IconColumns size={16}/>}>{t`Customize columns`}</Button>
                        </Menu.Target>
                        <Menu.Dropdown>
                            {(Object.keys(columnLabels) as ColumnKey[]).map(column => (
                                <Menu.Item key={column}>
                                    <Checkbox
                                        label={columnLabels[column]}
                                        checked={visibleColumns.includes(column)}
                                        onChange={() => setVisibleColumns(current => current.includes(column)
                                            ? current.filter(item => item !== column)
                                            : [...current, column])}
                                    />
                                </Menu.Item>
                            ))}
                        </Menu.Dropdown>
                    </Menu>
                    <Button variant="subtle" leftSection={<IconDownload size={16}/>} onClick={handleExport}>
                        {t`Export (CSV)`}
                    </Button>
                    <TextInput
                        className={classes.search}
                        leftSection={<IconSearch size={16}/>}
                        placeholder={t`Search`}
                        aria-label={t`Search a name, an email or a phone number`}
                        value={searchText}
                        onChange={(event) => {
                            setSearchText(event.currentTarget.value);
                            debouncedSearch(event.currentTarget.value);
                        }}
                    />
                </Group>
            </div>

            {selectedIds.length > 0 && (
                <div className={classes.selectionBar}>
                    <span>
                        <Trans>{selectedIds.length} selected contacts</Trans>
                    </span>
                    <Group gap="xs">
                        <TextInput
                            size="xs"
                            placeholder={t`Tag name`}
                            value={tagName}
                            onChange={(event) => setTagName(event.currentTarget.value)}
                            onKeyDown={(event) => event.key === 'Enter' && handleAssignTag()}
                        />
                        <Button size="xs" leftSection={<IconTag size={14}/>} onClick={handleAssignTag}
                                loading={assignTagMutation.isPending} disabled={!tagName.trim()}>
                            {t`Add a tag`}
                        </Button>
                    </Group>
                </div>
            )}

            <TableSkeleton isVisible={contactsQuery.isLoading}/>

            {!contactsQuery.isLoading && contacts.length === 0 && (
                <NoResultsSplash
                    heading={t`No contacts yet`}
                    subHeading={
                        <p>{t`Your buyers and attendees appear here as soon as an order is confirmed. You can also import a file.`}</p>
                    }
                />
            )}

            {contacts.length > 0 && (
                <div className={classes.tableCard}>
                    <Table.ScrollContainer minWidth={720}>
                        <Table highlightOnHover verticalSpacing="sm">
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th className={classes.checkboxCell}>
                                        <Checkbox
                                            aria-label={t`Select all`}
                                            checked={allSelected}
                                            indeterminate={!allSelected && selectedIds.length > 0}
                                            onChange={() => setSelectedIds(allSelected ? [] : contacts.map(contact => contact.id))}
                                        />
                                    </Table.Th>
                                    <Table.Th className={classes.headCell}>{t`Contact`}</Table.Th>
                                    {columns.map(column => (
                                        <Table.Th key={column} className={classes.headCell}>{columnLabels[column]}</Table.Th>
                                    ))}
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {contacts.map(contact => (
                                    <Table.Tr key={contact.id}>
                                        <Table.Td className={classes.checkboxCell}>
                                            <Checkbox
                                                aria-label={t`Select contact`}
                                                checked={selectedIds.includes(contact.id)}
                                                onChange={() => setSelectedIds(current => current.includes(contact.id)
                                                    ? current.filter(item => item !== contact.id)
                                                    : [...current, contact.id])}
                                            />
                                        </Table.Td>
                                        <Table.Td>
                                            <Anchor component={Link} underline="always" className={classes.name}
                                                    to={`/manage/organizer/${organizerId}/contacts/${contact.id}`}>
                                                {[contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.email || formatPhone(contact.phone)}
                                            </Anchor>
                                        </Table.Td>
                                        {columns.map(column => <Table.Td key={column}>{cell(contact, column)}</Table.Td>)}
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>
                </div>
            )}

            {pagination && (
                <Pagination
                    value={pagination.current_page}
                    total={pagination.last_page}
                    onChange={(page) => {
                        const next = new URLSearchParams(searchParams);
                        next.set('page', String(page));
                        setSearchParams(next);
                    }}
                />
            )}

            {modal === 'create' && <CreateContactModal organizerId={organizerId as IdParam} onClose={() => setModal(null)}/>}
            {modal === 'import' && <ImportContactsModal organizerId={organizerId as IdParam} onClose={() => setModal(null)}/>}
        </PageBody>
    );
};

export default Contacts;
