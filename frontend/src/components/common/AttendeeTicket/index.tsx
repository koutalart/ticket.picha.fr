import {getAttendeeProductTitle} from "../../../utilites/products.ts";
import {Button, CopyButton} from "@mantine/core";
import {t} from "@lingui/macro";
import {formatTicketDate, formatTicketHours} from "../../../utilites/dates.ts";
import QRCode from "react-qr-code";
import {
    IconCalendar,
    IconClock,
    IconCopy,
    IconLock,
    IconMapPin,
    IconPrinter,
    IconTicket,
    IconWorld,
    IconX,
} from "@tabler/icons-react";
import {Attendee, Event, Product} from "../../../types.ts";
import classes from './AttendeeTicket.module.scss';
import {PoweredByFooter} from "../PoweredByFooter";

interface AttendeeTicketProps {
    event: Event;
    attendee: Attendee;
    product: Product;
    hideButtons?: boolean;
    showPoweredBy?: boolean;
}

const PICHA_SITE = 'ticket.picha.fr';

const spaced = (text: string): string => text
    .split(' ')
    .map((word) => Array.from(word).join(' '))
    .join('   ');

const displayId = (publicId: string): string => {
    const [prefix, rest] = String(publicId).toUpperCase().split(/-(.*)/s);
    return rest || prefix;
};

export const AttendeeTicket = ({
                                   attendee,
                                   product,
                                   event,
                                   hideButtons = false,
                                   showPoweredBy = false,
                               }: AttendeeTicketProps) => {
    const ticketDesignSettings = event?.settings?.ticket_design_settings;
    const footerText = ticketDesignSettings?.footer_text;
    const showDate = (ticketDesignSettings?.date_display_mode || 'START_DATE_TIME') !== 'HIDDEN';
    const logoUrl = event?.images?.find((image) => image.type === 'TICKET_LOGO')?.url;
    const sponsorLogoUrl = event?.images?.find((image) => image.type === 'TICKET_SPONSOR_LOGO')?.url;
    const sponsorName = event?.settings?.ticket_sponsor_name;
    const timezone = event?.timezone || 'UTC';
    const location = event?.settings?.location_details;
    const venue = [location?.venue_name, location?.city].filter(Boolean).join(', ');
    const attendeeName = [attendee.first_name, attendee.last_name].filter(Boolean).join(' ');

    const isCancelled = attendee.status === 'CANCELLED';
    const isAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

    const rows = [
        {icon: IconTicket, label: t`Ticket type`, value: getAttendeeProductTitle(attendee, product)},
        {
            icon: IconCalendar,
            label: t`Date`,
            value: showDate && event?.start_date ? formatTicketDate(event.start_date, timezone) : '',
        },
        {
            icon: IconClock,
            label: t({message: 'Time', context: 'ticket'}),
            value: showDate && event?.start_date ? formatTicketHours(event.start_date, event.end_date, timezone) : '',
        },
        {icon: IconMapPin, label: t`Venue`, value: venue},
    ].filter((row) => row.value);

    const generateQrPattern = () => {
        const seed = attendee.public_id || 'default';
        const pattern = [];
        for (let i = 0; i < 64; i++) {
            const charCode = seed.charCodeAt(i % seed.length);
            pattern.push((charCode + i) % 2 === 0);
        }
        return pattern;
    };

    return (
        <div className={classes.ticket}>
            <div className={classes.header}>
                <div className={classes.headerBrand}>
                    {logoUrl
                        ? <img src={logoUrl} alt={event?.organizer?.name || event?.title} className={classes.eventLogo}/>
                        : <div className={classes.organizerName}>{event?.organizer?.name}</div>}
                </div>
                {(sponsorLogoUrl || sponsorName) && (
                    <>
                        <div className={classes.headerDivider}/>
                        <div className={classes.sponsor}>
                            <div className={classes.sponsorLabel}>{t`Sponsor`}</div>
                            {sponsorLogoUrl
                                ? <img src={sponsorLogoUrl} alt={sponsorName || t`Sponsor`} className={classes.sponsorLogo}/>
                                : <div className={classes.sponsorName}>{sponsorName}</div>}
                        </div>
                    </>
                )}
            </div>

            <div className={classes.eventLabel}>{spaced(t`Event`.toUpperCase())}</div>
            <h1 className={classes.eventTitle}>{event?.title}</h1>
            <div className={classes.titleBar}/>

            <div className={classes.body}>
                <div className={classes.rows}>
                    {rows.map(({icon: Icon, label, value}) => (
                        <div className={classes.row} key={label}>
                            <Icon className={classes.rowIcon} size={26} stroke={1.8}/>
                            <div>
                                <div className={classes.rowLabel}>{spaced(label.toUpperCase())}</div>
                                <div className={classes.rowValue}>{value}</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className={classes.qrColumn}>
                    {(isCancelled || isAwaitingPayment) ? (
                        <div
                            className={`${classes.qrPlaceholder} ${isCancelled ? classes.qrPlaceholderCancelled : classes.qrPlaceholderPending}`}>
                            <div className={classes.qrPatternBackground}>
                                {generateQrPattern().map((filled, i) => (
                                    <div
                                        key={i}
                                        className={`${classes.qrPatternCell} ${filled ? classes.qrPatternCellFilled : ''}`}
                                    />
                                ))}
                            </div>
                            <div className={classes.qrPlaceholderContent}>
                                <div
                                    className={`${classes.statusIconCircle} ${isCancelled ? classes.statusIconCancelled : classes.statusIconPending}`}>
                                    {isCancelled
                                        ? <IconX size={20} stroke={2} color="white"/>
                                        : <IconLock size={20} stroke={2} color="white"/>}
                                </div>
                                <span
                                    className={`${classes.statusText} ${isCancelled ? classes.statusTextCancelled : classes.statusTextPending}`}>
                                    {isCancelled ? t`Cancelled` : t`Pay to unlock`}
                                </span>
                            </div>
                        </div>
                    ) : (
                        <div className={classes.qrContainer}>
                            <QRCode
                                value={String(attendee.public_id)}
                                size={180}
                                level="Q"
                                style={{height: "auto", maxWidth: "100%", width: "100%"}}
                            />
                        </div>
                    )}
                    <div className={classes.ticketId}>{spaced(displayId(String(attendee.public_id)))}</div>
                    {attendeeName && <div className={classes.attendeeName}>{attendeeName}</div>}
                </div>
            </div>

            <div className={classes.footer}>
                <div className={classes.pichaBrand}>
                    <img src="/images/picha-ai-logo.png" alt="PICHA AI" className={classes.pichaLogo}/>
                    <span className={classes.pichaProduct}>Ticket</span>
                </div>
                <div className={classes.footerDivider}/>
                <div className={classes.footerSite}>
                    <div className={classes.footerLabel}>{t`Ticketing & management`}</div>
                    <div className={classes.siteUrl}>
                        <IconWorld size={22} stroke={1.8}/>
                        {PICHA_SITE}
                    </div>
                </div>
            </div>

            {footerText && <div className={classes.organizerFooter}>{footerText}</div>}

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

                    <CopyButton
                        value={`${window?.location.origin}/product/${event.id}/${attendee.short_id}`}>
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

            {showPoweredBy && (
                <div className={classes.poweredByInTicket}>
                    <PoweredByFooter/>
                </div>
            )}
        </div>
    );
}
