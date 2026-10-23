import {useState} from "react";
import {t} from "@lingui/macro";
import {IconMail} from "@tabler/icons-react";
import {Event} from "../../../types.ts";
import {PoweredByFooter} from "../PoweredByFooter";
import {ContactOrganizerModal} from "../ContactOrganizerModal";
import {EventRichFooter, hasEventRichFooter} from "../EventRichFooter";
import classes from "./EventImmersiveFooter.module.scss";

/**
 * Footer shown under the pages of an event using the fullscreen hero: the homepage rich footer when the
 * event has it, otherwise a minimal footer.
 */
export const EventImmersiveFooter = ({event}: { event: Event }) => {
    if (hasEventRichFooter(event)) {
        return <EventRichFooter event={event}/>;
    }

    return <EventImmersiveMinimalFooter event={event}/>;
};

const EventImmersiveMinimalFooter = ({event}: { event: Event }) => {
    const [contactOpen, setContactOpen] = useState(false);
    const organizer = event.organizer;

    return (
        <footer className={classes.footer}>
            {organizer && (
                <div className={classes.organizer}>
                    <span className={classes.label}>{t`Organized by`}</span>
                    <span className={classes.name}>{organizer.name}</span>
                    <button type="button" className={classes.contact} onClick={() => setContactOpen(true)}>
                        <IconMail size={16}/>
                        {t`Contact the organizer`}
                    </button>
                </div>
            )}
            <PoweredByFooter className={classes.poweredBy}/>
            {organizer && (
                <ContactOrganizerModal
                    opened={contactOpen}
                    onClose={() => setContactOpen(false)}
                    organizer={organizer}
                />
            )}
        </footer>
    );
};

export default EventImmersiveFooter;
