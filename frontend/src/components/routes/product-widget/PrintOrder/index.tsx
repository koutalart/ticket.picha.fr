import {AttendeeTicket} from "../../../common/AttendeeTicket";
import {Product} from "../../../../types.ts";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {useParams} from "react-router";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {t} from "@lingui/macro";
import {useEffect} from "react";
import classes from './PrintOrder.module.scss';

export const PrintOrder = () => {
    const {eventId, orderShortId} = useParams();
    const {data: order} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const event = order?.event;

    useEffect(() => {
        if (order && event) {
            setTimeout(() => window?.print(), 500);
        }
    }, [order, event]);

    if (!order || !event) {
        return null;
    }

    return (
        <div className={classes.container}>
            <h2 className={classes.title}>{t`Tickets for`} {event.title}</h2>
            {order.attendees?.map((attendee) => {
                return (
                    <div key={attendee.id} className={classes.ticketPage}>
                        <AttendeeTicket
                            attendee={attendee}
                            product={attendee.product as Product}
                            event={event}
                            hideButtons
                            showPoweredBy
                        />
                    </div>
                );
            })}
            
            {/* PoweredBy footer for web view only */}
            <div className={classes.webOnlyFooter}>
                <PoweredByFooter/>
            </div>
        </div>
    );
}

export default PrintOrder;
