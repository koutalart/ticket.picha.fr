import {RefObject} from "react";
import {t} from "@lingui/macro";
import {IconCalendarOff} from "@tabler/icons-react";
import {TICKETS_SECTION_ID} from "../../../../utilites/ticketsAnchor.ts";
import classes from "./EventEndedNotice.module.scss";

interface EventEndedNoticeProps {
    variant: 'card' | 'immersive';
    sectionRef?: RefObject<HTMLElement | HTMLDivElement>;
    className?: string;
    otherEventsUrl?: string;
}

/**
 * Replaces the ticket form once the event is over: same "tickets" anchor, so "?acheter=1" and "#tickets" links
 * land on this message instead of an empty form.
 */
export const EventEndedNotice = ({variant, sectionRef, className, otherEventsUrl}: EventEndedNoticeProps) => {
    const content = (
        <>
            <IconCalendarOff size={22} className={classes.icon} aria-hidden="true"/>
            <div>
                <h2 className={classes.title} id="event-ended-title">{t`This event has ended.`}</h2>
                <p className={classes.text}>{t`Ticket sales are closed.`}</p>
                {otherEventsUrl && (
                    <a href={otherEventsUrl} className={classes.link}>{t`See the organizer's other events`}</a>
                )}
            </div>
        </>
    );

    if (variant === 'immersive') {
        return (
            <section
                className={`${classes.immersive} ${className ?? ''}`}
                ref={sectionRef as RefObject<HTMLElement>}
                id={TICKETS_SECTION_ID}
                aria-labelledby="event-ended-title"
            >
                <div className={classes.panel}>{content}</div>
            </section>
        );
    }

    return (
        <div
            className={className}
            ref={sectionRef as RefObject<HTMLDivElement>}
            id={TICKETS_SECTION_ID}
        >
            <div className={classes.card} role="status">{content}</div>
        </div>
    );
};
