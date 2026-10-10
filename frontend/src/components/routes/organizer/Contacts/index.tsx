import {useMemo, useState} from "react";
import {useNavigate, useParams, useSearchParams} from "react-router";
import {t, Trans} from "@lingui/macro";
import {Badge, Button, Checkbox, Chip, Group, Select, Table, TextInput} from "@mantine/core";
import {useDebouncedCallback} from "@mantine/hooks";
import {IconDownload, IconSearch, IconTag} from "@tabler/icons-react";
import {PageBody} from "../../../common/PageBody";
import {PageTitle} from "../../../common/PageTitle";
import {Pagination} from "../../../common/Pagination";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {NoResultsSplash} from "../../../common/NoResultsSplash";
import {useGetContacts} from "../../../../queries/useGetContacts.ts";
import {useGetContactsSummary} from "../../../../queries/useGetContactsSummary.ts";
import {useGetContactTags} from "../../../../queries/useGetContactTags.ts";
import {useGetOrganizerEvents} from "../../../../queries/useGetOrganizerEvents.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useAssignContactTag} from "../../../../mutations/useContactTags.ts";
import {contactClient, ContactFilters} from "../../../../api/contact.client.ts";
import {IdParam} from "../../../../types.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../utilites/dates.ts";
import {formatPhone} from "../../../../utilites/phone.ts";
import {downloadBinary} from "../../../../utilites/download.ts";
import {withLoadingNotification} from "../../../../utilites/withLoadingNotification.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./Contacts.module.scss";

const FLAG_FILTERS = ['email_consent', 'sms_consent', 'attended', 'has_phone'] as const;

const Contacts = () => {
    const {organizerId} = useParams();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const [searchText, setSearchText] = useState(searchParams.get('query') ?? '');
    const [selectedIds, setSelectedIds] = useState<IdParam[]>([]);
    const [tagName, setTagName] = useState('');

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
    const summaryQuery = useGetContactsSummary(organizerId, filters);
    const {data: tags} = useGetContactTags(organizerId);
    const {data: events} = useGetOrganizerEvents(organizerId, {pageNumber: 1, perPage: 100});
    const assignTagMutation = useAssignContactTag();

    const contacts = contactsQuery.data?.data ?? [];
    const pagination = contactsQuery.data?.meta;
    const summary = summaryQuery.data;
    const currency = organizer?.currency ?? 'EUR';
    const timezone = organizer?.timezone ?? 'UTC';

    const updateParam = (key: string, value?: string | null) => {
        const next = new URLSearchParams(searchParams);
        if (value) {
            next.set(key, value);
        } else {
            next.delete(key);
        }
        next.delete('page');
        setSelectedIds([]);
        setSearchParams(next, {replace: true});
    };

    const debouncedSearch = useDebouncedCallback((value: string) => updateParam('query', value.trim()), 350);

    const toggleSelected = (id: IdParam) => {
        setSelectedIds(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]);
    };

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

    const formatDay = (date?: string | null) => date ? formatDateWithLocale(date, 'shortDate', timezone) : '—';

    return (
        <PageBody>
            <PageTitle subheading={t`Everyone who bought or received a ticket from you.`}>
                {t`Contacts`}
            </PageTitle>

            <div className={classes.kpis}>
                <div className={classes.kpi}>
                    <div className={classes.kpiValue}>{summary?.total ?? '—'}</div>
                    <div className={classes.kpiLabel}>{t`contacts`}</div>
                </div>
                <div className={classes.kpi}>
                    <div className={classes.kpiValue}>{summary?.email_consent ?? '—'}</div>
                    <div className={classes.kpiLabel}>{t`accept emails`}</div>
                </div>
                <div className={classes.kpi}>
                    <div className={classes.kpiValue}>{summary?.repeat_customers ?? '—'}</div>
                    <div className={classes.kpiLabel}>{t`came to 2 events or more`}</div>
                </div>
                <div className={classes.kpi}>
                    <div className={classes.kpiValue}>{summary ? formatCurrency(summary.total_spent, currency) : '—'}</div>
                    <div className={classes.kpiLabel}>{t`spent in total`}</div>
                </div>
            </div>

            <div className={classes.filters}>
                <TextInput
                    className={classes.search}
                    leftSection={<IconSearch size={16}/>}
                    placeholder={t`Search a name, an email or a phone number`}
                    value={searchText}
                    onChange={(event) => {
                        setSearchText(event.currentTarget.value);
                        debouncedSearch(event.currentTarget.value);
                    }}
                />
                <Select
                    placeholder={t`All events`}
                    clearable
                    searchable
                    data={(events?.data ?? []).map(event => ({value: String(event.id), label: event.title}))}
                    value={filters.event_id ?? null}
                    onChange={(value) => updateParam('event_id', value)}
                />
                <Select
                    placeholder={t`All tags`}
                    clearable
                    data={(tags ?? []).map(tag => ({value: String(tag.id), label: `${tag.name} (${tag.contacts_count ?? 0})`}))}
                    value={filters.tag_id ?? null}
                    onChange={(value) => updateParam('tag_id', value)}
                />
                <Button variant="default" leftSection={<IconDownload size={16}/>} onClick={handleExport}>
                    {t`Export (CSV)`}
                </Button>
            </div>

            <Group gap="xs" mb="md">
                {FLAG_FILTERS.map(flag => (
                    <Chip
                        key={flag}
                        size="sm"
                        checked={filters[flag] === true}
                        onChange={(checked) => updateParam(flag, checked ? '1' : null)}
                    >
                        {{
                            email_consent: t`Accept emails`,
                            sms_consent: t`Accept SMS`,
                            attended: t`Came (scanned)`,
                            has_phone: t`With a phone number`,
                        }[flag]}
                    </Chip>
                ))}
            </Group>

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
                        <p>{t`Your buyers and attendees appear here as soon as an order is confirmed.`}</p>
                    }
                />
            )}

            {contacts.length > 0 && (
                <Table.ScrollContainer minWidth={640}>
                    <Table highlightOnHover verticalSpacing="sm">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>
                                    <Checkbox
                                        aria-label={t`Select all`}
                                        checked={allSelected}
                                        indeterminate={!allSelected && selectedIds.length > 0}
                                        onChange={() => setSelectedIds(allSelected ? [] : contacts.map(contact => contact.id))}
                                    />
                                </Table.Th>
                                <Table.Th>{t`Contact`}</Table.Th>
                                <Table.Th className={classes.hideOnMobile}>{t`Phone`}</Table.Th>
                                <Table.Th>{t`Events`}</Table.Th>
                                <Table.Th className={classes.hideOnMobile}>{t`Last purchase`}</Table.Th>
                                <Table.Th>{t`Total`}</Table.Th>
                                <Table.Th>{t`Emails`}</Table.Th>
                                <Table.Th className={classes.hideOnMobile}>{t`Tags`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {contacts.map(contact => (
                                <Table.Tr key={contact.id} className={classes.row}
                                          onClick={() => navigate(`/manage/organizer/${organizerId}/contacts/${contact.id}`)}>
                                    <Table.Td onClick={(event) => event.stopPropagation()}>
                                        <Checkbox
                                            aria-label={t`Select contact`}
                                            checked={selectedIds.includes(contact.id)}
                                            onChange={() => toggleSelected(contact.id)}
                                        />
                                    </Table.Td>
                                    <Table.Td>
                                        <div className={classes.name}>
                                            {[contact.first_name, contact.last_name].filter(Boolean).join(' ') || '—'}
                                        </div>
                                        <div className={classes.email}>{contact.email}</div>
                                    </Table.Td>
                                    <Table.Td className={`${classes.numeric} ${classes.hideOnMobile}`}>{formatPhone(contact.phone)}</Table.Td>
                                    <Table.Td className={classes.numeric}>{contact.events_count}</Table.Td>
                                    <Table.Td className={`${classes.numeric} ${classes.hideOnMobile}`}>{formatDay(contact.last_order_at)}</Table.Td>
                                    <Table.Td className={classes.numeric}>{formatCurrency(contact.total_spent, currency)}</Table.Td>
                                    <Table.Td>
                                        <Badge variant="light" color={contact.email_consent ? 'green' : 'gray'}>
                                            {contact.email_consent ? t`yes` : t`no`}
                                        </Badge>
                                    </Table.Td>
                                    <Table.Td className={classes.hideOnMobile}>
                                        <div className={classes.tags}>
                                            {contact.tags.map(tag => (
                                                <Badge key={tag.id} variant="light" size="sm">{tag.name}</Badge>
                                            ))}
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                </Table.ScrollContainer>
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
        </PageBody>
    );
};

export default Contacts;
