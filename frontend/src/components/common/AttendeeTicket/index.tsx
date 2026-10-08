import {Button, CopyButton} from "@mantine/core";
import {t} from "@lingui/macro";
import {IconCopy, IconPrinter} from "@tabler/icons-react";
import {Attendee, Event, Product} from "../../../types.ts";
import classes from './AttendeeTicket.module.scss';
import {attendeeTicketImageUrl} from "../../../utilites/urlHelper.ts";

interface AttendeeTicketProps {
    event: Event;
    attendee: Attendee;
    product?: Product;
    hideButtons?: boolean;
    showPoweredBy?: boolean;
    imageSrc?: string;
}

export const AttendeeTicket = ({
                                   attendee,
                                   event,
                                   hideButtons = false,
                                   imageSrc,
                               }: AttendeeTicketProps) => {
    const src = imageSrc ?? attendeeTicketImageUrl(event.id, attendee.short_id, attendee.status);
    const label = `${event?.title} — ${attendee.first_name} ${attendee.last_name} — ${attendee.public_id}`;

    return (
        <div className={classes.ticket}>
            <img className={classes.image} src={src} alt={label} width={639} height={639} loading="lazy"/>

            {!hideButtons && (
                <div className={classes.actions}>
                    <Button
                        variant="default"
                        size="sm"
                        onClick={() => window?.open(`/product/${event.id}/${attendee.short_id}/print`, '_blank')}
                        leftSection={<IconPrinter size={16}/>}
                    >
                        {t`Print to PDF`}
                    </Button>

                    <CopyButton value={`${window?.location.origin}/product/${event.id}/${attendee.short_id}`}>
                        {({copied, copy}) => (
                            <Button
                                variant="default"
                                size="sm"
                                onClick={copy}
                                leftSection={<IconCopy size={16}/>}
                            >
                                {copied ? t`Copied` : t`Copy Link`}
                            </Button>
                        )}
                    </CopyButton>
                </div>
            )}
        </div>
    );
}
