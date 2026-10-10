import {useState} from "react";
import {Link, useParams} from "react-router";
import {t} from "@lingui/macro";
import {ActionIcon, Anchor, Avatar, Badge, Button, Group, SegmentedControl, Textarea, TextInput} from "@mantine/core";
import {IconArrowLeft, IconCash, IconCheck, IconMail, IconMessageQuestion, IconPencil, IconTicket, IconTrash, IconX} from "@tabler/icons-react";
import {PageBody} from "../../../../common/PageBody";
import {Card} from "../../../../common/Card";
import {LoadingMask} from "../../../../common/LoadingMask";
import {useGetContact} from "../../../../../queries/useGetContact.ts";
import {useGetContactTimeline} from "../../../../../queries/useGetContactTimeline.ts";
import {useGetContactNotes} from "../../../../../queries/useGetContactNotes.ts";
import {useGetOrganizer} from "../../../../../queries/useGetOrganizer.ts";
import {useCreateContactNote, useDeleteContactNote} from "../../../../../mutations/useContactNotes.ts";
import {useAssignContactTag, useRemoveContactTag} from "../../../../../mutations/useContactTags.ts";
import {ContactTimelineItem, ContactTimelineType} from "../../../../../api/contact.client.ts";
import {IdParam} from "../../../../../types.ts";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import {showError} from "../../../../../utilites/notifications.tsx";
import {EditContactModal} from "./EditContactModal.tsx";
import classes from "./ContactDetail.module.scss";

type TimelineFilter = 'ALL' | 'ORDERS' | 'CHECK_IN' | 'MESSAGE' | 'ANSWER';

const TIMELINE_FILTERS: Record<TimelineFilter, ContactTimelineType[]> = {
    ALL: ['ORDER', 'TICKET_RECEIVED', 'CHECK_IN', 'MESSAGE', 'ANSWER'],
    ORDERS: ['ORDER', 'TICKET_RECEIVED'],
    CHECK_IN: ['CHECK_IN'],
    MESSAGE: ['MESSAGE'],
    ANSWER: ['ANSWER'],
};

const TIMELINE_ICONS: Record<ContactTimelineType, { icon: typeof IconCash, color: string }> = {
    ORDER: {icon: IconCash, color: 'violet'},
    TICKET_RECEIVED: {icon: IconTicket, color: 'violet'},
    CHECK_IN: {icon: IconCheck, color: 'green'},
    MESSAGE: {icon: IconMail, color: 'orange'},
    ANSWER: {icon: IconMessageQuestion, color: 'gray'},
};

const ContactDetail = () => {
    const {organizerId, contactId} = useParams();
    const [editing, setEditing] = useState(false);
    const [timelineFilter, setTimelineFilter] = useState<TimelineFilter>('ALL');
    const [noteText, setNoteText] = useState('');
    const [tagName, setTagName] = useState('');

    const {data: organizer} = useGetOrganizer(organizerId);
    const contactQuery = useGetContact(organizerId, contactId);
    const {data: timeline} = useGetContactTimeline(organizerId, contactId);
    const {data: notes} = useGetContactNotes(organizerId, contactId);
    const createNote = useCreateContactNote();
    const deleteNote = useDeleteContactNote();
    const assignTag = useAssignContactTag();
    const removeTag = useRemoveContactTag();

    const contact = contactQuery.data;
    const timezone = organizer?.timezone ?? 'UTC';
    const currency = organizer?.currency ?? 'EUR';
    const ids = {organizerId: organizerId as IdParam, contactId: contactId as IdParam};

    if (contactQuery.isLoading || !contact) {
        return <LoadingMask/>;
    }

    const fullName = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.email || t`Contact`;
    const initials = fullName.split(' ').map(part => part.charAt(0)).join('').slice(0, 2).toUpperCase();
    const formatDay = (date?: string | null) => date ? formatDateWithLocale(date, 'shortDate', timezone) : '—';
    const formatDateTime = (date: string) => formatDateWithLocale(date, 'shortDateTime', timezone);

    const timelineTitle = (item: ContactTimelineItem): string => {
        switch (item.type) {
            case 'ORDER':
                return t`Order ${item.title ?? ''}`;
            case 'TICKET_RECEIVED':
                return t`Ticket received from ${item.detail ?? ''}`;
            case 'CHECK_IN':
                return t`Entry scanned`;
            case 'MESSAGE':
                return t`Email received: ${item.title ?? ''}`;
            case 'ANSWER':
                return item.title ?? t`Answer`;
        }
    };

    const timelineDetail = (item: ContactTimelineItem): string | null => {
        switch (item.type) {
            case 'ORDER':
                return [
                    item.amount !== null && item.amount !== undefined ? formatCurrency(item.amount, item.currency ?? currency) : null,
                    item.status === 'CANCELLED' ? t`cancelled` : null,
                    item.detail ? t`Tickets: ${item.detail}` : null,
                ].filter(Boolean).join(' · ');
            case 'CHECK_IN':
                return item.detail ?? null;
            case 'ANSWER':
                return item.detail ?? null;
            default:
                return null;
        }
    };

    const visibleTimeline = (timeline ?? []).filter(item => TIMELINE_FILTERS[timelineFilter].includes(item.type));

    const handleAddNote = () => {
        if (!noteText.trim()) {
            return;
        }
        createNote.mutate({...ids, note: noteText.trim()}, {
            onSuccess: () => setNoteText(''),
            onError: () => showError(t`The note could not be saved. Please try again.`),
        });
    };

    const handleAddTag = () => {
        if (!tagName.trim()) {
            return;
        }
        assignTag.mutate({organizerId: ids.organizerId, name: tagName.trim(), contactIds: [contact.id]}, {
            onSuccess: () => setTagName(''),
            onError: () => showError(t`The tag could not be added. Please try again.`),
        });
    };

    const consentBadge = (active: boolean, at?: string | null) => active
        ? <Badge variant="light" color="green">{t`accepts`} · {formatDay(at)}</Badge>
        : <Badge variant="light" color="gray">{t`no consent`}</Badge>;

    return (
        <PageBody>
            <Anchor component={Link} to={`/manage/organizer/${organizerId}/contacts`} size="sm" mb="md">
                <Group gap={4}><IconArrowLeft size={14}/>{t`Back to contacts`}</Group>
            </Anchor>

            <div className={classes.header}>
                <div className={classes.identity}>
                    <Avatar color="primary" radius="xl" size="lg">{initials}</Avatar>
                    <div>
                        <h1 className={classes.name}>{fullName}</h1>
                        <div className={classes.subtitle}>
                            {t`${contact.events_count} events`} · {formatCurrency(contact.total_spent, currency)}
                            {contact.first_order_at ? ` · ${t`customer since`} ${formatDay(contact.first_order_at)}` : ''}
                        </div>
                    </div>
                </div>
                <Button variant="default" leftSection={<IconPencil size={16}/>} onClick={() => setEditing(true)}>
                    {t`Edit`}
                </Button>
            </div>

            <div className={classes.layout}>
                <div className={classes.side}>
                    <Card>
                        <dl className={classes.details}>
                            <dt>{t`Email`}</dt>
                            <dd>{contact.email ?? '—'}</dd>
                            <dt>{t`Phone`}</dt>
                            <dd>{contact.phone ?? '—'}</dd>
                            <dt>{t`Emails`}</dt>
                            <dd>{consentBadge(contact.email_consent, contact.email_consent_at)}</dd>
                            <dt>{t`SMS`}</dt>
                            <dd>{consentBadge(contact.sms_consent, contact.sms_consent_at)}</dd>
                            <dt>{t`Came`}</dt>
                            <dd>{t`${contact.attended_events_count} of ${contact.events_count} events`}</dd>
                            <dt>{t`Orders`}</dt>
                            <dd>{contact.orders_count}</dd>
                        </dl>
                    </Card>

                    <Card>
                        <div className={classes.sectionLabel}>{t`Tags`}</div>
                        <Group gap={6} mb="sm">
                            {contact.tags.map(tag => (
                                <Badge key={tag.id} variant="light"
                                       rightSection={
                                           <ActionIcon size="xs" variant="transparent" aria-label={t`Remove tag`}
                                                       onClick={() => removeTag.mutate({...ids, tagId: tag.id})}>
                                               <IconX size={12}/>
                                           </ActionIcon>
                                       }>
                                    {tag.name}
                                </Badge>
                            ))}
                        </Group>
                        <Group gap="xs" wrap="nowrap">
                            <TextInput size="xs" placeholder={t`New tag`} value={tagName}
                                       onChange={(event) => setTagName(event.currentTarget.value)}
                                       onKeyDown={(event) => event.key === 'Enter' && handleAddTag()}/>
                            <Button size="xs" variant="light" onClick={handleAddTag} disabled={!tagName.trim()}
                                    loading={assignTag.isPending}>
                                {t`Add`}
                            </Button>
                        </Group>
                    </Card>

                    <Card>
                        <div className={classes.sectionLabel}>{t`Internal notes`}</div>
                        <Textarea autosize minRows={2} placeholder={t`Only your team can see these notes`}
                                  value={noteText} onChange={(event) => setNoteText(event.currentTarget.value)}/>
                        <Button size="xs" mt="xs" mb="sm" onClick={handleAddNote} disabled={!noteText.trim()}
                                loading={createNote.isPending}>
                            {t`Add a note`}
                        </Button>
                        <Group gap="xs" style={{flexDirection: 'column', alignItems: 'stretch'}}>
                            {(notes ?? []).map(note => (
                                <div key={note.id} className={classes.note}>
                                    {note.note}
                                    <div className={classes.noteMeta}>
                                        <span>{note.author ?? ''} · {formatDay(note.created_at)}</span>
                                        <ActionIcon size="xs" variant="subtle" color="gray" aria-label={t`Delete note`}
                                                    onClick={() => deleteNote.mutate({...ids, noteId: note.id})}>
                                            <IconTrash size={12}/>
                                        </ActionIcon>
                                    </div>
                                </div>
                            ))}
                        </Group>
                    </Card>
                </div>

                <Card>
                    <SegmentedControl
                        size="xs"
                        value={timelineFilter}
                        onChange={(value) => setTimelineFilter(value as TimelineFilter)}
                        data={[
                            {value: 'ALL', label: t`All`},
                            {value: 'ORDERS', label: t`Orders`},
                            {value: 'CHECK_IN', label: t`Entries`},
                            {value: 'MESSAGE', label: t`Messages`},
                            {value: 'ANSWER', label: t`Answers`},
                        ]}
                    />
                    {visibleTimeline.length === 0 && (
                        <p className={classes.subtitle}>{t`Nothing to show for now.`}</p>
                    )}
                    <ul className={classes.timeline}>
                        {visibleTimeline.map((item, index) => {
                            const {icon: Icon, color} = TIMELINE_ICONS[item.type];
                            const detail = timelineDetail(item);
                            return (
                                <li key={`${item.type}-${item.occurred_at}-${index}`} className={classes.timelineItem}>
                                    <span className={classes.timelineDate}>{formatDateTime(item.occurred_at)}</span>
                                    <Avatar size={26} radius="sm" color={color} variant="light"><Icon size={14}/></Avatar>
                                    <div>
                                        <strong>{timelineTitle(item)}</strong>
                                        {item.event_title && <span> · {item.event_title}</span>}
                                        {detail && <div className={classes.timelineDetail}>{detail}</div>}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </Card>
            </div>

            {editing && (
                <EditContactModal organizerId={ids.organizerId} contact={contact} onClose={() => setEditing(false)}/>
            )}
        </PageBody>
    );
};

export default ContactDetail;
