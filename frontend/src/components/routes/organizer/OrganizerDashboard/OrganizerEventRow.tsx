import {useState} from "react";
import {useNavigate} from "react-router";
import {Button} from "@mantine/core";
import {IconArchive, IconCopy, IconDots, IconEye, IconInfoCircle, IconSettings} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {Event, IdParam} from "../../../../types.ts";
import {ActionMenu, ActionMenuItemsGroup} from "../../../common/ActionMenu";
import {DuplicateEventModal} from "../../../modals/DuplicateEventModal";
import {useUpdateEventStatus} from "../../../../mutations/useUpdateEventStatus.ts";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {eventHomepagePath} from "../../../../utilites/urlHelper.ts";
import classes from "./OrganizerDashboard.module.scss";

const initialsOf = (title: string) => title
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 3)
    .map((word) => word.charAt(0).toUpperCase())
    .join('');

const statusOf = (event: Event) => {
    if (event.status === 'ARCHIVED') return {label: t`Archived`, tone: 'muted'};
    if (event.lifecycle_status === 'ENDED') return {label: t`Ended`, tone: 'muted'};
    if (event.status === 'DRAFT') return {label: t`Draft`, tone: 'draft'};
    if (event.lifecycle_status === 'ONGOING') return {label: t({message: 'Live', context: 'ongoing event'}), tone: 'ongoing'};
    return {label: t`On Sale`, tone: 'live'};
};

const formatEventDate = (event: Event) => new Intl.DateTimeFormat(i18n.locale, {
    day: 'numeric', month: 'long', year: 'numeric', timeZone: event.timezone,
}).format(new Date(event.start_date));

export const OrganizerEventRow = ({event}: { event: Event }) => {
    const navigate = useNavigate();
    const [duplicateId, setDuplicateId] = useState<IdParam>();
    const statusMutation = useUpdateEventStatus();
    const [coverFailed, setCoverFailed] = useState(false);

    const cover = event.images?.find((image) => image.type === 'EVENT_COVER');
    const status = statusOf(event);
    const isDraft = event.status === 'DRAFT';
    const place = event.settings?.is_online_event
        ? t`Online`
        : event.settings?.location_details?.venue_name || event.settings?.location_details?.city;

    const toggleArchive = () => {
        const message = event.status !== 'ARCHIVED'
            ? t`Are you sure you want to archive this event?`
            : t`Are you sure you want to restore this event? It will be restored as a draft event.`;

        confirmationDialog(message, () => {
            statusMutation.mutate({
                eventId: event.id,
                status: event.status === 'ARCHIVED' ? 'DRAFT' : 'ARCHIVED',
            }, {
                onSuccess: () => showSuccess(t`Event status updated`),
                onError: (error: any) => showError(error?.response?.data?.message || t`Event status update failed. Please try again later`),
            });
        });
    };

    const menu: ActionMenuItemsGroup[] = [{
        label: '',
        items: [
            {label: t`View event page`, icon: <IconEye size={14}/>, onClick: () => window.open(eventHomepagePath(event), '_blank')},
            {label: t`Manage event`, icon: <IconSettings size={14}/>, onClick: () => navigate(`/manage/event/${event.id}`)},
            {label: t`Duplicate event`, icon: <IconCopy size={14}/>, onClick: () => setDuplicateId(event.id)},
            {
                label: event.status === 'ARCHIVED' ? t`Restore event` : t`Archive event`,
                icon: <IconArchive size={14}/>,
                onClick: toggleArchive,
            },
        ],
    }];

    const open = () => navigate(isDraft ? `/manage/event/${event.id}/getting-started` : `/manage/event/${event.id}`);

    return (
        <article className={classes.eventRow}>
            <div className={classes.eventMain}>
                <button type="button" className={classes.eventThumb} onClick={open} aria-label={event.title}>
                    {cover && !coverFailed
                        ? <img src={cover.url} alt="" onError={() => setCoverFailed(true)}/>
                        : <span>{initialsOf(event.title)}</span>}
                </button>

                <div className={classes.eventInfo}>
                    <button type="button" className={classes.eventTitle} onClick={open}>{event.title}</button>
                    <span className={`${classes.eventStatus} ${classes[`tone_${status.tone}`]}`}>
                        <span className={classes.statusDot}/>{status.label}
                    </span>
                    <span className={classes.eventMeta}>
                        {formatEventDate(event)}
                        {place && <><span className={classes.metaSeparator}>•</span>{place}</>}
                    </span>
                </div>

                <div className={classes.eventActions}>
                    <Button radius="md" size="md" className={classes.eventButton} onClick={open}>
                        {isDraft ? <Trans>Resume</Trans> : <Trans>Manage</Trans>}
                    </Button>
                    <ActionMenu itemsGroups={menu} target={(
                        <span className={classes.moreButton} aria-label={t`More actions`}><IconDots size={22}/></span>
                    )}/>
                </div>
            </div>

            {isDraft && (
                <p className={classes.eventHint}>
                    <IconInfoCircle size={18} stroke={1.5}/>
                    <Trans>Complete the details before publishing your event.</Trans>
                </p>
            )}

            {duplicateId && <DuplicateEventModal eventId={duplicateId} onClose={() => setDuplicateId(undefined)}/>}
        </article>
    );
};
