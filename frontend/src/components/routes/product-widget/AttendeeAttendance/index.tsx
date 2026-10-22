import {CSSProperties, useState} from "react";
import {useParams, useSearchParams} from "react-router";
import {Button, Loader, Modal} from "@mantine/core";
import {IconCalendarEvent, IconCircleCheck, IconCircleX, IconMapPin} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useGetAttendeeAttendancePublic} from "../../../../queries/useGetAttendeeAttendancePublic.ts";
import {useCancelAttendancePublic, useConfirmAttendancePublic} from "../../../../mutations/useRespondToAttendancePublic.ts";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {computeThemeVariables, validateThemeSettings} from "../../../../utilites/themeUtils.ts";
import {eventCoverImageUrl, eventHomepagePath} from "../../../../utilites/urlHelper.ts";
import {formatEventDateRange} from "../../../../utilites/dates.ts";
import {getFullscreenHero} from "../../../../utilites/fullscreenHero.ts";
import {EventImmersiveShell} from "../../../common/EventImmersiveShell";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./AttendeeAttendance.module.scss";

const errorMessage = (error: any, fallback: string): string => error?.response?.data?.message || fallback;

export const AttendeeAttendance = () => {
    const {eventId, attendeeShortId} = useParams();
    const [searchParams] = useSearchParams();
    const code = searchParams.get('code') || '';
    const [confirmCancelOpen, setConfirmCancelOpen] = useState(false);

    const {data: event, isError: eventError} = useGetEventPublic(eventId);
    const attendanceQuery = useGetAttendeeAttendancePublic(eventId, String(attendeeShortId), code);
    const attendance = attendanceQuery.data;
    const confirmMutation = useConfirmAttendancePublic();
    const cancelMutation = useCancelAttendancePublic();

    if (!code || eventError || attendanceQuery.error?.response?.status === 404) {
        return (
            <EventImmersiveShell event={event} panel={false}>
                <HomepageInfoMessage
                    status="not_found"
                    message={t`Link not valid`}
                    subtitle={t`This personal link is not valid. Please use the link from your latest email.`}
                />
            </EventImmersiveShell>
        );
    }

    if (attendanceQuery.isError) {
        return (
            <EventImmersiveShell event={event} panel={false}>
                <HomepageInfoMessage
                    status="info"
                    message={t`Attendance confirmation unavailable`}
                    subtitle={errorMessage(attendanceQuery.error, t`The organizer has not enabled attendance confirmation for this event.`)}
                />
            </EventImmersiveShell>
        );
    }

    if (!event || !attendance) {
        return (
            <div className={classes.loading}>
                <Loader/>
            </div>
        );
    }

    const theme = validateThemeSettings(event.settings?.homepage_theme_settings);
    const themeStyle = computeThemeVariables(theme) as unknown as CSSProperties;
    const immersive = getFullscreenHero(event).enabled;
    const coverImage = immersive ? undefined : eventCoverImageUrl(event);
    const location = event.settings?.location_details;
    const place = event.settings?.is_online_event
        ? t`Online event`
        : [location?.venue_name, location?.city].filter(Boolean).join(', ');
    const isCancelled = attendance.status === 'CANCELLED';
    const isConfirmed = !!attendance.attendance_confirmed_at && !isCancelled;
    const requestParams = {eventId: event.id, attendeeShortId: String(attendeeShortId), code};

    const handleConfirm = () => {
        confirmMutation.mutate(requestParams, {
            onSuccess: () => showSuccess(t`Thank you, your attendance is confirmed.`),
            onError: (error) => showError(errorMessage(error, t`We could not save your answer. Please try again.`)),
        });
    };

    const handleCancel = () => {
        cancelMutation.mutate(requestParams, {
            onSuccess: () => {
                setConfirmCancelOpen(false);
                showSuccess(t`Your registration has been cancelled.`);
            },
            onError: (error) => {
                setConfirmCancelOpen(false);
                showError(errorMessage(error, t`We could not cancel your registration. Please try again.`));
            },
        });
    };

    const card = (
        <main className={classes.card} data-immersive={immersive || undefined}>
            {coverImage && (
                <div className={classes.cover}>
                    <img src={coverImage} alt=""/>
                </div>
            )}

            <div className={classes.content}>
                <h1 className={classes.title}>{event.title}</h1>
                <div className={classes.meta}>
                    <span>
                        <IconCalendarEvent size={16}/>
                        {formatEventDateRange(event.start_date, event.end_date, event.timezone)}
                    </span>
                    {place && (
                        <span>
                            <IconMapPin size={16}/>
                            {place}
                        </span>
                    )}
                </div>

                <div className={classes.greeting}>
                    <p className={classes.hello}>{t`Hello ${attendance.first_name},`}</p>
                    {attendance.product_title && (
                        <p className={classes.ticket}>{t`Your registration: ${attendance.product_title}`}</p>
                    )}
                </div>

                {isCancelled && (
                    <div className={`${classes.notice} ${classes.noticeCancelled}`}>
                        <IconCircleX size={22}/>
                        <div>
                            <strong>{t`Your registration is cancelled.`}</strong>
                            <p>{t`Your place has been released for another person. Thank you for letting us know.`}</p>
                        </div>
                    </div>
                )}

                {isConfirmed && (
                    <div className={`${classes.notice} ${classes.noticeConfirmed}`}>
                        <IconCircleCheck size={22}/>
                        <div>
                            <strong>{t`Your attendance is confirmed.`}</strong>
                            <p>{t`See you soon! If your plans change, you can still let us know below.`}</p>
                        </div>
                    </div>
                )}

                {!isCancelled && !attendance.can_respond && attendance.blocking_reason && (
                    <div className={classes.notice}>
                        <div>
                            <p>{attendance.blocking_reason}</p>
                        </div>
                    </div>
                )}

                {attendance.can_respond && (
                    <>
                        {!isConfirmed && (
                            <p className={classes.question}>{t`Will you be there? Your answer helps the organizer plan the event and offer free places to people on the waiting list.`}</p>
                        )}
                        <div className={classes.actions}>
                            {!isConfirmed && (
                                <Button
                                    size="md"
                                    radius="md"
                                    className={classes.primaryButton}
                                    leftSection={<IconCircleCheck size={20}/>}
                                    loading={confirmMutation.isPending}
                                    disabled={cancelMutation.isPending}
                                    onClick={handleConfirm}
                                >
                                    {t`I'm coming`}
                                </Button>
                            )}
                            <Button
                                size="md"
                                radius="md"
                                variant="outline"
                                color="red"
                                leftSection={<IconCircleX size={20}/>}
                                disabled={confirmMutation.isPending}
                                onClick={() => setConfirmCancelOpen(true)}
                            >
                                {t`I'm no longer coming`}
                            </Button>
                        </div>
                    </>
                )}

                <a className={classes.eventLink} href={eventHomepagePath(event)}>
                    {t`View the event page`}
                </a>
            </div>
        </main>
    );

    const cancelModal = (
        <Modal
            opened={confirmCancelOpen}
            onClose={() => setConfirmCancelOpen(false)}
            title={t`Cancel your registration?`}
            centered
            radius="md"
        >
            <p className={classes.modalText}>
                {t`Your ticket will be cancelled and your place will be offered to someone else. This cannot be undone.`}
            </p>
            <div className={classes.modalActions}>
                <Button variant="default" onClick={() => setConfirmCancelOpen(false)} disabled={cancelMutation.isPending}>
                    {t`Keep my place`}
                </Button>
                <Button color="red" onClick={handleCancel} loading={cancelMutation.isPending}>
                    {t`Yes, cancel my registration`}
                </Button>
            </div>
        </Modal>
    );

    if (immersive) {
        return (
            <EventImmersiveShell event={event}>
                {card}
                {cancelModal}
            </EventImmersiveShell>
        );
    }

    return (
        <div className={classes.page} style={themeStyle} data-mode={theme.mode}>
            {card}
            <PoweredByFooter/>
            {cancelModal}
        </div>
    );
};

export default AttendeeAttendance;
