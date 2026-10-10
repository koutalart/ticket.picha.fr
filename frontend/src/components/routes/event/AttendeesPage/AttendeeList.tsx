import {useState} from "react";
import {useParams} from "react-router";
import {Menu} from "@mantine/core";
import {IconCircleCheckFilled, IconClockHour4, IconDots, IconMailForward, IconReceipt, IconSend, IconTrash, IconUserCog} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {Attendee, Event, IdParam, MessageType} from "../../../../types.ts";
import {SendMessageModal} from "../../../modals/SendMessageModal";
import {ManageAttendeeModal} from "../../../modals/ManageAttendeeModal";
import {ManageOrderModal} from "../../../modals/ManageOrderModal";
import {CheckInStatusModal} from "../../../common/CheckInStatusModal";
import {useModifyAttendee} from "../../../../mutations/useModifyAttendee.ts";
import {useResendAttendeeTicket} from "../../../../mutations/useResendAttendeeTicket.ts";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {getProductFromEvent} from "../../../../utilites/helpers.ts";
import {displayAttendeeEmail} from "../../../../utilites/isKioskSentinelEmail.ts";
import classes from "./AttendeesPage.module.scss";

type Modal = 'manage' | 'message' | 'checkIn' | 'order';

const initials = (attendee: Attendee) => [attendee.first_name, attendee.last_name]
    .filter(Boolean)
    .map((part) => part.trim().charAt(0).toUpperCase())
    .join('') || '?';

const entryTime = (date: string, timezone: string) => {
    const value = new Date(date);
    const sameDay = new Date().toDateString() === value.toDateString();
    const time = new Intl.DateTimeFormat(i18n.locale, {hour: '2-digit', minute: '2-digit', timeZone: timezone}).format(value);
    if (sameDay) return time;
    const day = new Intl.DateTimeFormat(i18n.locale, {day: 'numeric', month: 'short', timeZone: timezone}).format(value);
    return `${day}, ${time}`;
};

export const AttendeeList = ({attendees, event}: { attendees: Attendee[], event: Event }) => {
    const {eventId} = useParams();
    const [selected, setSelected] = useState<Attendee>();
    const [modal, setModal] = useState<Modal | null>(null);
    const modifyMutation = useModifyAttendee();
    const resendMutation = useResendAttendeeTicket();

    const open = (attendee: Attendee, which: Modal) => {
        setSelected(attendee);
        setModal(which);
    };
    const close = () => setModal(null);

    const resendTicket = (attendee: Attendee) => resendMutation.mutate({attendeeId: attendee.id, eventId}, {
        onSuccess: () => showSuccess(t`Ticket email has been resent to attendee`),
        onError: (error: any) => showError(error?.response?.data?.message || t`Failed to resend ticket email`),
    });

    const toggleCancel = (attendee: Attendee) => {
        const cancelled = attendee.status === 'CANCELLED';
        confirmationDialog(cancelled
            ? t`Are you sure you want to activate this attendee?`
            : t`Are you sure you want to cancel this attendee? This will void their ticket`, () => {
            modifyMutation.mutate({
                attendeeId: attendee.id,
                eventId,
                attendeeData: {status: cancelled ? 'ACTIVE' : 'CANCELLED'},
            }, {
                onSuccess: () => showSuccess(cancelled ? t`Attendee activated successfully` : t`Attendee cancelled successfully`),
                onError: () => showError(t`Failed to cancel attendee`),
            });
        });
    };

    const entry = (attendee: Attendee) => {
        if (attendee.status === 'CANCELLED') {
            return <span className={`${classes.pill} ${classes.pillCancelled}`}><Trans>Cancelled</Trans></span>;
        }
        if (attendee.status === 'AWAITING_PAYMENT') {
            return <span className={`${classes.pill} ${classes.pillWaiting}`}>{t({message: 'Awaiting payment', context: 'order status'})}</span>;
        }
        const firstCheckIn = [...(attendee.check_ins ?? [])].sort((a, b) => a.created_at.localeCompare(b.created_at))[0];
        return (
            <button type="button" className={firstCheckIn ? classes.entered : classes.notEntered}
                    onClick={(clickEvent) => {
                        clickEvent.stopPropagation();
                        open(attendee, 'checkIn');
                    }}>
                {firstCheckIn ? (
                    <>
                        <IconCircleCheckFilled size={20}/>
                        <Trans>Entered at {entryTime(firstCheckIn.created_at, event.timezone)}</Trans>
                    </>
                ) : (
                    <>
                        <IconClockHour4 size={20}/>
                        <Trans>Not entered yet</Trans>
                    </>
                )}
            </button>
        );
    };

    return (
        <>
            <div className={classes.list}>
                {attendees.map((attendee) => {
                    const email = displayAttendeeEmail(attendee.email);
                    const ticket = getProductFromEvent(attendee.product_id, event)?.title;
                    const name = [attendee.first_name, attendee.last_name].filter(Boolean).join(' ') || t`No name`;
                    return (
                        <div key={attendee.id}
                             className={`${classes.row} ${attendee.status === 'CANCELLED' ? classes.rowCancelled : ''}`}
                             role="button" tabIndex={0}
                             onClick={() => open(attendee, 'manage')}
                             onKeyDown={(keyEvent) => keyEvent.key === 'Enter' && open(attendee, 'manage')}>
                            <span className={classes.avatar} aria-hidden="true">{initials(attendee)}</span>
                            <div className={classes.person}>
                                <span className={classes.name}>{name}</span>
                                <span className={classes.secondary}>{email ?? t`Sold at the box office`}</span>
                            </div>
                            <div className={classes.ticket}>
                                <span className={classes.ticketName}>{ticket}</span>
                                <span className={classes.secondary}>
                                    <Trans>Ticket</Trans> {attendee.public_id}
                                    {attendee.order?.public_id && <> · <Trans>order</Trans> {attendee.order.public_id}</>}
                                </span>
                            </div>
                            <div className={classes.entry}>{entry(attendee)}</div>
                            <div className={classes.actions} onClick={(clickEvent) => clickEvent.stopPropagation()}>
                                <Menu shadow="md" width={230} position="bottom-end">
                                    <Menu.Target>
                                        <button type="button" className={classes.moreButton} aria-label={t`More actions`}>
                                            <IconDots size={20}/>
                                        </button>
                                    </Menu.Target>
                                    <Menu.Dropdown>
                                        <Menu.Item leftSection={<IconUserCog size={14}/>} onClick={() => open(attendee, 'manage')}>
                                            {t`Manage attendee`}
                                        </Menu.Item>
                                        <Menu.Item leftSection={<IconReceipt size={14}/>} onClick={() => open(attendee, 'order')}>
                                            {t`View order`}
                                        </Menu.Item>
                                        <Menu.Item leftSection={<IconSend size={14}/>} onClick={() => open(attendee, 'message')}>
                                            {t`Message attendee`}
                                        </Menu.Item>
                                        {attendee.status === 'ACTIVE' && email && (
                                            <Menu.Item leftSection={<IconMailForward size={14}/>} onClick={() => resendTicket(attendee)}>
                                                {t`Resend ticket email`}
                                            </Menu.Item>
                                        )}
                                        <Menu.Divider/>
                                        <Menu.Item leftSection={<IconTrash size={14}/>}
                                                   color={attendee.status === 'CANCELLED' ? 'green' : 'red'}
                                                   onClick={() => toggleCancel(attendee)}>
                                            {attendee.status === 'CANCELLED' ? t`Reactivate the ticket` : t`Cancel the ticket`}
                                        </Menu.Item>
                                    </Menu.Dropdown>
                                </Menu>
                            </div>
                        </div>
                    );
                })}
            </div>

            {selected && modal === 'message' && (
                <SendMessageModal onClose={close} orderId={selected.order_id} attendeeId={selected.id}
                                  messageType={MessageType.IndividualAttendees}/>
            )}
            {selected?.id && modal === 'manage' && <ManageAttendeeModal attendeeId={selected.id} onClose={close}/>}
            {selected && modal === 'checkIn' && (
                <CheckInStatusModal attendee={selected} eventTimezone={event.timezone} eventId={eventId as IdParam}
                                    isOpen onClose={close}/>
            )}
            {selected && modal === 'order' && <ManageOrderModal orderId={selected.order_id} onClose={close}/>}
        </>
    );
};
