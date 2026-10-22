import {useParams} from 'react-router';
import {useGetEventPublic} from '../../../../queries/useGetEventPublic.ts';
import {useGetAttendeePublic} from '../../../../queries/useGetAttendeePublic.ts';
import {AttendeeTicket} from '../../../common/AttendeeTicket';
import {Attendee, Product} from '../../../../types.ts';
import {PoweredByFooter} from '../../../common/PoweredByFooter';
import {useEffect} from "react";
import {OnlineEventDetails} from "../../../common/OnlineEventDetails";
import {t} from '@lingui/macro';
import classes from '../PrintOrder/PrintOrder.module.scss';
import {getFullscreenHero} from "../../../../utilites/fullscreenHero.ts";
import {EventImmersiveShell} from "../../../common/EventImmersiveShell";

const PrintProduct = () => {
    const {eventId, attendeeShortId} = useParams();
    const {data: event} = useGetEventPublic(eventId);
    const {data: attendee} = useGetAttendeePublic(eventId, String(attendeeShortId));

    const immersive = getFullscreenHero(event).enabled;

    useEffect(() => {
        if (attendee && event) {
            setTimeout(() => window?.print(), 500);
        }
    }, [attendee, event]);

    if (!event || !attendee) {
        return null;
    }

    return (
        <EventImmersiveShell event={event} width="wide">
            <div className={classes.container}>
                <h2 className={classes.title}>{t`Ticket for`} {event.title}</h2>
                <div className={classes.ticketPage}>
                    <AttendeeTicket
                        attendee={attendee as Attendee}
                        product={attendee.product as Product}
                        event={event}
                        hideButtons
                    />

                    {(event?.settings?.is_online_event && (
                        <div style={{ marginTop: '32px', maxWidth: '900px', width: '100%' }}>
                            <OnlineEventDetails eventSettings={event.settings}/>
                        </div>
                    ))}
                    
                    <div className={classes.poweredBy}>
                        <PoweredByFooter/>
                    </div>
                </div>
                
                {!immersive && (
                    <div className={classes.webOnlyFooter}>
                        <PoweredByFooter/>
                    </div>
                )}
            </div>
        </EventImmersiveShell>
    )
}

export default PrintProduct;
