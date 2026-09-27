import {useState} from "react";
import {useParams} from "react-router";
import {t, Trans} from "@lingui/macro";
import {Button, Loader, TextInput} from "@mantine/core";
import {useDebouncedValue} from "@mantine/hooks";
import {IconDoorEnter, IconId, IconPrinter, IconSearch} from "@tabler/icons-react";
import dayjs from "dayjs";
import {utcToTz} from "../../../../utilites/dates.ts";
import {BoxOfficeAttendeeSearchResult, BoxOfficeCheckInResult} from "../../../../api/box-office.client.ts";
import {useGetBoxOfficeContext} from "../../../../queries/useGetBoxOfficeContext.ts";
import {useSearchBoxOfficeAttendees} from "../../../../queries/useSearchBoxOfficeAttendees.ts";
import {useCheckInBoxOfficeAttendee} from "../../../../mutations/useCheckInBoxOfficeAttendee.ts";
import {KioskPrintOutput, useKioskSettings} from "../../../../hooks/useKioskSettings.ts";
import {useKioskTicketPrinter} from "../../../../hooks/useKioskTicketPrinter.tsx";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import classes from "./Welcome.module.scss";

const KioskWelcome = () => {
    const {eventId} = useParams();
    const context = useGetBoxOfficeContext();
    const {settings, setSettings} = useKioskSettings();
    const currentEvent = (context.data?.data ?? []).find((event) => String(event.id) === String(eventId));

    const [query, setQuery] = useState('');
    const [debouncedQuery] = useDebouncedValue(query.trim(), 250);
    const [busyId, setBusyId] = useState<string | null>(null);
    const searchQuery = useSearchBoxOfficeAttendees(eventId, debouncedQuery);
    const checkIn = useCheckInBoxOfficeAttendee();

    const {printTickets, printerPromptModal} = useKioskTicketPrinter({
        eventId: eventId as string,
        printMode: settings.printOutput as KioskPrintOutput,
        skipPrint: settings.printOutput === 'none',
        zebraPrinterHost: settings.zebraPrinterHost || currentEvent?.last_printer_host || '',
        onZebraPrinterHostChange: (zebraPrinterHost) => setSettings({...settings, zebraPrinterHost}),
    });

    const results = debouncedQuery.length >= 2 ? (searchQuery.data?.data ?? []) : [];

    const formatTime = (value: string | null) => {
        if (!value) {
            return '';
        }
        return currentEvent?.timezone
            ? (utcToTz(value, currentEvent.timezone) ?? '').slice(11, 16)
            : dayjs(value).format('HH:mm');
    };

    const fullName = (attendee: BoxOfficeAttendeeSearchResult) =>
        [attendee.first_name, attendee.last_name].filter(Boolean).join(' ') || attendee.public_id;

    const announceCheckIn = (attendee: BoxOfficeAttendeeSearchResult, result: BoxOfficeCheckInResult) => {
        if (result.status === 'CHECKED_IN') {
            showSuccess(t`${fullName(attendee)} checked in`);
        } else if (result.status === 'ALREADY_CHECKED_IN') {
            showError(t`${fullName(attendee)} already entered at ${formatTime(result.checked_in_at)}`);
        } else {
            showError(result.message || t`This ticket cannot be checked in.`);
        }
    };

    const runCheckIn = async (attendee: BoxOfficeAttendeeSearchResult) => {
        try {
            const response = await checkIn.mutateAsync({eventId: eventId as string, attendeePublicId: attendee.public_id});
            announceCheckIn(attendee, response.data);
        } catch {
            showError(t`Check-in failed. Please try again.`);
        }
    };

    const handleAction = async (attendee: BoxOfficeAttendeeSearchResult, action: 'badge-and-entry' | 'badge' | 'entry') => {
        setBusyId(attendee.public_id);
        try {
            if (action !== 'entry') {
                await printTickets([attendee.public_id]);
            }
            if (action !== 'badge') {
                await runCheckIn(attendee);
            }
        } finally {
            setBusyId(null);
        }
    };

    const handleSubmit = () => {
        const exact = results.find((attendee) => attendee.public_id.toLowerCase() === query.trim().toLowerCase());
        if (exact && exact.status === 'ACTIVE' && !exact.checked_in_at) {
            void handleAction(exact, 'badge-and-entry');
            setQuery('');
        }
    };

    const statusLabel = (attendee: BoxOfficeAttendeeSearchResult) => {
        if (attendee.status === 'CANCELLED') {
            return <span className={`${classes.status} ${classes.statusRefused}`}>{t`Cancelled`}</span>;
        }
        if (attendee.status === 'AWAITING_PAYMENT') {
            return <span className={`${classes.status} ${classes.statusWarning}`}>{t`Payment pending`}</span>;
        }
        if (attendee.checked_in_at) {
            return <span className={`${classes.status} ${classes.statusDone}`}>{t`Entered at ${formatTime(attendee.checked_in_at)}`}</span>;
        }
        return <span className={`${classes.status} ${classes.statusReady}`}>{t`Registered`}</span>;
    };

    return (
        <div className={classes.page}>
            {printerPromptModal}
            <header className={classes.header}>
                <h1 className={classes.title}>{currentEvent?.title}</h1>
                <p className={classes.subtitle}>{t`Welcome desk — find a registered attendee, print the badge and record the entry.`}</p>
            </header>

            <form
                className={classes.search}
                onSubmit={(event) => {
                    event.preventDefault();
                    handleSubmit();
                }}
            >
                <TextInput
                    size="xl"
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.currentTarget.value)}
                    placeholder={t`Name, e-mail or ticket number — or scan the QR code`}
                    leftSection={<IconSearch/>}
                    rightSection={searchQuery.isFetching ? <Loader size="sm"/> : null}
                    aria-label={t`Search attendee`}
                />
            </form>

            <div className={classes.results}>
                {debouncedQuery.length < 2 && (
                    <p className={classes.hint}>
                        <Trans>Type at least 2 characters, or scan the attendee's QR code.</Trans>
                    </p>
                )}
                {debouncedQuery.length >= 2 && !searchQuery.isFetching && results.length === 0 && (
                    <p className={classes.hint}>{t`No attendee found.`}</p>
                )}
                {results.map((attendee) => {
                    const blocked = attendee.status === 'CANCELLED';
                    const isBusy = busyId === attendee.public_id;
                    return (
                        <article key={attendee.public_id} className={classes.card}>
                            <div className={classes.identity}>
                                <div className={classes.name}>{fullName(attendee)}</div>
                                <div className={classes.meta}>
                                    {attendee.product_title} · {attendee.public_id}
                                    {attendee.email ? ` · ${attendee.email}` : ''}
                                </div>
                                {statusLabel(attendee)}
                            </div>
                            <div className={classes.actions}>
                                <Button
                                    size="lg"
                                    leftSection={<IconId/>}
                                    disabled={blocked || isBusy}
                                    loading={isBusy}
                                    onClick={() => void handleAction(attendee, 'badge-and-entry')}
                                >
                                    {t`Badge + entry`}
                                </Button>
                                <Button
                                    size="lg"
                                    variant="light"
                                    leftSection={<IconPrinter/>}
                                    disabled={blocked || isBusy}
                                    onClick={() => void handleAction(attendee, 'badge')}
                                >
                                    {t`Badge only`}
                                </Button>
                                <Button
                                    size="lg"
                                    variant="default"
                                    leftSection={<IconDoorEnter/>}
                                    disabled={blocked || isBusy}
                                    onClick={() => void handleAction(attendee, 'entry')}
                                >
                                    {t`Entry only`}
                                </Button>
                            </div>
                        </article>
                    );
                })}
            </div>
        </div>
    );
};

export default KioskWelcome;
