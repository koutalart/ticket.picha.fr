import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useParams} from "react-router";
import {useGetAttendeePublic} from "../../../../queries/useGetAttendeePublic.ts";
import {AttendeeTicket} from "../../../common/AttendeeTicket";
import {Attendee, Product} from "../../../../types.ts";
import {Container} from "@mantine/core";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {OnlineEventDetails} from "../../../common/OnlineEventDetails";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import classes from './AttendeeProductAndInformation.module.scss';
import {getFullscreenHero} from "../../../../utilites/fullscreenHero.ts";
import {EventImmersiveShell} from "../../../common/EventImmersiveShell";

export const AttendeeProductAndInformation = () => {
    const {eventId, attendeeShortId} = useParams();
    const {data: event, isError: eventError} = useGetEventPublic(eventId);
    const {data: attendee, isError: attendeeError} = useGetAttendeePublic(eventId, String(attendeeShortId));

    if (eventError || attendeeError) {
        return (
            <EventImmersiveShell event={event} panel={false}>
                <HomepageInfoMessage
                    status="not_found"
                    message={t`Ticket Not Found`}
                    subtitle={t`We couldn't find the ticket you're looking for. The link may have expired or the ticket details may have changed.`}
                />
            </EventImmersiveShell>
        );
    }

    if (!event || !attendee) {
        return null;
    }

    const content = (
        <>
            <h2 className={classes.title}>{t`Your ticket for`} {event.title}</h2>

            <AttendeeTicket
                attendee={attendee as Attendee}
                product={attendee.product as Product}
                event={event}
                showPoweredBy
            />

            {(event?.settings?.is_online_event && <OnlineEventDetails eventSettings={event.settings}/>)}
        </>
    );

    if (getFullscreenHero(event).enabled) {
        return (
            <EventImmersiveShell event={event} width="wide">
                {content}
            </EventImmersiveShell>
        );
    }

    return (
        <Container>
            {content}
            <PoweredByFooter/>
        </Container>
    )
}

export default AttendeeProductAndInformation;
