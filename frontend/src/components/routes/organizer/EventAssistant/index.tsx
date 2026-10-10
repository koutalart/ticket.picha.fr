import {FormEvent, useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {NavLink, useNavigate, useSearchParams} from "react-router";
import {Button, Loader, NumberInput, SegmentedControl, Select, Textarea, TextInput} from "@mantine/core";
import {Dropzone, FileRejection} from "@mantine/dropzone";
import {IconArrowLeft, IconArrowRight, IconCalendarEvent, IconPhoto, IconPlus, IconSparkles, IconX} from "@tabler/icons-react";
import {useGetOrganizers} from "../../../../queries/useGetOrganizers.ts";
import {useGetEventAssistantStatus} from "../../../../queries/useGetEventAssistantStatus.ts";
import {useReadEventPoster} from "../../../../mutations/useReadEventPoster.ts";
import {useImproveEventDescription} from "../../../../mutations/useImproveEventDescription.ts";
import {useCreateEventFromAssistant} from "../../../../mutations/useCreateEventFromAssistant.ts";
import {EventDraft} from "../../../../api/event-assistant.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../types.ts";
import classes from "./EventAssistant.module.scss";

type StepId = 'essentials' | 'details';
type FieldErrors = Partial<Record<'title' | 'startDate' | 'endDate' | 'price' | 'organizer', string>>;

interface Draft {
    title: string;
    startDate: string;
    hasEndDate: boolean;
    endDate: string;
    venue: string;
    description: string;
    isFree: boolean;
    price: number | '';
    quantity: number | '';
}

const EMPTY_DRAFT: Draft = {
    title: '',
    startDate: '',
    hasEndDate: false,
    endDate: '',
    venue: '',
    description: '',
    isFree: false,
    price: '',
    quantity: '',
};

const POSTER_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
const MAX_POSTER_SIZE = 8 * 1024 * 1024;

const storageKey = (organizerId: IdParam | undefined) => `picha-event-assistant-${organizerId ?? 'default'}`;

const readStoredDraft = (organizerId: IdParam | undefined): Draft => {
    try {
        const stored = typeof window !== 'undefined' ? window.localStorage.getItem(storageKey(organizerId)) : null;
        return stored ? {...EMPTY_DRAFT, ...JSON.parse(stored)} : EMPTY_DRAFT;
    } catch {
        return EMPTY_DRAFT;
    }
};

const capitalize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

const formatDay = (value: string) => new Intl.DateTimeFormat(i18n.locale, {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
}).format(new Date(value));

const formatHour = (value: string) => {
    const date = new Date(value);
    if (i18n.locale.startsWith('fr')) {
        return `${String(date.getHours()).padStart(2, '0')}h${String(date.getMinutes()).padStart(2, '0')}`;
    }
    return new Intl.DateTimeFormat(i18n.locale, {hour: 'numeric', minute: '2-digit'}).format(date);
};

const describeDates = (start: string, end: string | null): string => {
    if (!start || Number.isNaN(new Date(start).getTime())) {
        return '';
    }
    if (!end || Number.isNaN(new Date(end).getTime())) {
        return capitalize(t`${formatDay(start)} from ${formatHour(start)}`);
    }
    if (start.slice(0, 10) === end.slice(0, 10)) {
        return capitalize(t`${formatDay(start)} from ${formatHour(start)} to ${formatHour(end)}`);
    }
    return capitalize(t`From ${formatDay(start)} at ${formatHour(start)} to ${formatDay(end)} at ${formatHour(end)}`);
};

const toApiDate = (value: string) => value.replace('T', ' ');

const EventAssistant = () => {
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const organizersQuery = useGetOrganizers();
    const statusQuery = useGetEventAssistantStatus();
    const readPoster = useReadEventPoster();
    const improveDescription = useImproveEventDescription();
    const createEvent = useCreateEventFromAssistant();

    const organizers = organizersQuery.data?.data ?? [];
    const [organizerId, setOrganizerId] = useState<string | null>(searchParams.get('organizer'));
    const organizer = organizers.find((item) => String(item.id) === organizerId) ?? (organizers.length === 1 ? organizers[0] : undefined);
    const aiEnabled = statusQuery.data?.ai_enabled === true;

    const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT);
    const [draftLoaded, setDraftLoaded] = useState(false);
    const [poster, setPoster] = useState<File | null>(null);
    const [posterPreview, setPosterPreview] = useState<string | null>(null);
    const [previousDescription, setPreviousDescription] = useState<string | null>(null);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [aiNotice, setAiNotice] = useState<string | null>(null);

    const [step, setStep] = useState<StepId>('essentials');

    useEffect(() => {
        setDraft(readStoredDraft(searchParams.get('organizer') ?? undefined));
        setDraftLoaded(true);
    }, []);

    useEffect(() => {
        if (!organizerId && organizers.length === 1) {
            setOrganizerId(String(organizers[0].id));
        }
    }, [organizers.length]);

    useEffect(() => {
        if (!draftLoaded) return;
        try {
            window.localStorage.setItem(storageKey(searchParams.get('organizer') ?? undefined), JSON.stringify(draft));
        } catch {
            // Private browsing: the draft simply isn't kept.
        }
    }, [draft]);

    useEffect(() => () => {
        if (posterPreview) URL.revokeObjectURL(posterPreview);
    }, [posterPreview]);

    const update = (values: Partial<Draft>) => {
        setErrors((current) => {
            const next = {...current};
            Object.keys(values).forEach((key) => delete next[key as keyof FieldErrors]);
            return next;
        });
        setFormError(null);
        setDraft((current) => ({...current, ...values}));
    };

    const goTo = (target: StepId) => {
        setErrors({});
        setFormError(null);
        setStep(target);
        if (typeof window !== 'undefined') window.scrollTo({top: 0});
    };

    const keepPoster = (file: File) => {
        setPoster(file);
        setPosterPreview(URL.createObjectURL(file));
    };

    const applyDraft = (found: EventDraft) => {
        setDraft((current) => ({
            ...current,
            title: found.title ?? current.title,
            startDate: found.start_date ?? current.startDate,
            hasEndDate: found.end_date ? true : current.hasEndDate,
            endDate: found.end_date ?? current.endDate,
            venue: found.venue ?? current.venue,
            description: found.description ?? current.description,
            isFree: found.price === 0 ? true : current.isFree,
            price: found.price !== null && found.price > 0 ? found.price : current.price,
        }));
    };

    const handlePoster = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        keepPoster(file);
        setAiNotice(null);
        setFormError(null);

        if (!aiEnabled) {
            return;
        }

        readPoster.mutate({poster: file, timezone: organizer?.timezone}, {
            onSuccess: ({data}) => {
                applyDraft(data);
                setAiNotice(t`We filled in what we could read on your poster. Have a quick look before going on.`);
            },
            onError: () => setAiNotice(t`We couldn't read your poster, but it's kept as your cover. Just fill in the rest.`),
        });
    };

    const rejectPoster = (rejections: FileRejection[]) => {
        const tooBig = rejections.some((rejection) => rejection.errors.some((item) => item.code === 'file-too-large'));
        setFormError(tooBig ? t`This image is over 8 MB. Try a lighter one.` : t`We accept JPG, PNG, WebP and HEIC images.`);
    };

    const handleImprove = () => {
        improveDescription.mutate({
            description: draft.description,
            title: draft.title || undefined,
            venue: draft.venue || undefined,
        }, {
            onSuccess: ({data}) => {
                setPreviousDescription(draft.description);
                update({description: data.description});
            },
            onError: () => showError(t`The AI couldn't rewrite your text this time. Try again in a moment.`),
        });
    };

    const validateEssentials = (): FieldErrors => {
        const found: FieldErrors = {};
        if (!draft.title.trim()) found.title = t`Your event needs a name.`;
        if (!draft.startDate) found.startDate = t`Tell us when it starts.`;
        if (draft.hasEndDate && draft.endDate && draft.startDate && draft.endDate <= draft.startDate) {
            found.endDate = t`The end has to come after the start.`;
        }
        return found;
    };

    const validateDetails = (): FieldErrors => {
        const found: FieldErrors = {};
        if (!draft.isFree && (draft.price === '' || Number(draft.price) <= 0)) {
            found.price = t`Enter a ticket price, or choose "Free".`;
        }
        if (!organizer) found.organizer = t`Choose who is organizing this event.`;
        return found;
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();

        if (step === 'essentials') {
            const found = validateEssentials();
            setErrors(found);
            if (Object.keys(found).length === 0) goTo('details');
            return;
        }

        const found = validateDetails();
        setErrors(found);
        if (Object.keys(found).length > 0 || !organizer) return;

        createEvent.mutate({
            payload: {
                organizer_id: organizer.id as IdParam,
                title: draft.title.trim(),
                start_date: toApiDate(draft.startDate),
                end_date: draft.hasEndDate && draft.endDate ? toApiDate(draft.endDate) : null,
                venue: draft.venue.trim() || null,
                description: draft.description.trim() || null,
                price: draft.isFree ? 0 : Number(draft.price),
                quantity: draft.quantity === '' ? null : Number(draft.quantity),
            },
            cover: poster,
        }, {
            onSuccess: ({event, coverUploaded}) => {
                try {
                    window.localStorage.removeItem(storageKey(searchParams.get('organizer') ?? undefined));
                } catch {
                    // Nothing to clean.
                }
                if (!coverUploaded) {
                    showError(t`Your event is created, but we couldn't add the poster. You can add it from the event settings.`);
                }
                navigate(`/manage/event/${event.id}/getting-started?new_event=true`);
            },
            onError: () => setFormError(t`Something went wrong and your event wasn't created. Check the details and try again.`),
        });
    };

    const dashboardUrl = organizer ? `/manage/organizer/${organizer.id}/dashboard` : '/manage/events';
    const endValue = draft.hasEndDate ? draft.endDate : null;
    const stepNumber = step === 'details' ? 2 : 1;

    const renderEssentials = () => (
        <>
            <h1 className={classes.title}><Trans>Your event</Trans></h1>
            <p className={classes.subtitle}><Trans>The essentials: what, when and where.</Trans></p>

            {posterPreview ? (
                <div className={classes.posterBar}>
                    <img src={posterPreview} alt=""/>
                    <div className={classes.posterBarText}>
                        {readPoster.isPending ? (
                            <span className={classes.posterReading}><Loader size="xs"/> <Trans>Reading your poster…</Trans></span>
                        ) : (
                            <span>{aiNotice ?? t`Your poster will be the cover of the event page.`}</span>
                        )}
                    </div>
                    {!readPoster.isPending && (
                        <button type="button" className={classes.clearButton} aria-label={t`Remove the poster`} onClick={() => {
                            setPoster(null);
                            setPosterPreview(null);
                            setAiNotice(null);
                        }}>
                            <IconX size={18}/>
                        </button>
                    )}
                </div>
            ) : (
                <Dropzone onDrop={handlePoster} onReject={rejectPoster} accept={POSTER_TYPES} maxSize={MAX_POSTER_SIZE}
                          multiple={false} className={classes.posterDrop}>
                    <div className={classes.posterDropInner}>
                        {aiEnabled ? <IconSparkles size={22}/> : <IconPhoto size={22}/>}
                        <span>
                            <strong><Trans>You have a poster?</Trans></strong>{' '}
                            {aiEnabled
                                ? <Trans>Drop it here, we fill in the form for you.</Trans>
                                : <Trans>Drop it here, it will be the cover of your event.</Trans>}
                        </span>
                    </div>
                </Dropzone>
            )}
            {formError && step === 'essentials' && <p className={classes.error}>{formError}</p>}

            <div className={classes.fields}>
                <TextInput
                    autoFocus={!draft.title}
                    label={t`Event name`}
                    size="lg"
                    radius="xl"
                    classNames={{input: classes.input, label: classes.label}}
                    value={draft.title}
                    maxLength={150}
                    placeholder={t`E.g. Open day at Mayotte la 1ère`}
                    error={errors.title}
                    onChange={(event) => update({title: event.currentTarget.value})}
                />

                <div>
                    <div className={classes.dateRow}>
                        <TextInput
                            type="datetime-local"
                            label={t`Starts`}
                            size="lg"
                            radius="xl"
                            classNames={{input: classes.input, label: classes.label}}
                            value={draft.startDate}
                            error={errors.startDate}
                            onChange={(event) => update({startDate: event.currentTarget.value})}
                        />
                        {draft.hasEndDate && (
                            <TextInput
                                type="datetime-local"
                                label={t`Ends`}
                                size="lg"
                                radius="xl"
                                classNames={{input: classes.input, label: classes.label}}
                                value={draft.endDate}
                                min={draft.startDate || undefined}
                                error={errors.endDate}
                                onChange={(event) => update({endDate: event.currentTarget.value})}
                                rightSection={
                                    <button type="button" className={classes.clearButton} aria-label={t`Remove the end time`}
                                            onClick={() => update({hasEndDate: false, endDate: ''})}>
                                        <IconX size={16}/>
                                    </button>
                                }
                            />
                        )}
                    </div>
                    {!draft.hasEndDate && (
                        <button type="button" className={classes.addLink} onClick={() => update({hasEndDate: true})}>
                            <IconPlus size={16}/> <Trans>Add an end time</Trans>
                        </button>
                    )}
                    {draft.startDate && (
                        <p className={classes.datePreview}>
                            <IconCalendarEvent size={18}/> {describeDates(draft.startDate, endValue)}
                        </p>
                    )}
                </div>

                <TextInput
                    label={t`Where?`}
                    description={t`Optional. A venue name, a town or a full address.`}
                    size="lg"
                    radius="xl"
                    classNames={{input: classes.input, label: classes.label, description: classes.fieldHint}}
                    value={draft.venue}
                    maxLength={255}
                    placeholder={t`E.g. Le 5/5, Mamoudzou`}
                    onChange={(event) => update({venue: event.currentTarget.value})}
                />
            </div>

            <div className={classes.footer}>
                <span/>
                <Button type="submit" size="lg" radius="xl" className={classes.primaryButton}
                        rightSection={<IconArrowRight size={18}/>}>
                    <Trans>Next</Trans>
                </Button>
            </div>
        </>
    );

    const renderDetails = () => (
        <>
            <h1 className={classes.title}><Trans>Tickets and presentation</Trans></h1>
            <p className={classes.subtitle}><Trans>Last step. Apart from the price, everything is optional.</Trans></p>

            <div className={classes.fields}>
                <div>
                    <span className={classes.label}><Trans>Entry</Trans></span>
                    <SegmentedControl
                        fullWidth
                        size="md"
                        radius="xl"
                        className={classes.segmented}
                        classNames={{indicator: classes.segmentedIndicator, label: classes.segmentedLabel}}
                        value={draft.isFree ? 'free' : 'paid'}
                        onChange={(value) => update({isFree: value === 'free'})}
                        data={[{value: 'paid', label: t`Paid`}, {value: 'free', label: t`Free`}]}
                    />
                </div>

                <div className={classes.priceGrid}>
                    {!draft.isFree && (
                        <NumberInput
                            label={t`Ticket price (€)`}
                            size="lg"
                            radius="xl"
                            min={0}
                            hideControls
                            decimalScale={2}
                            decimalSeparator={i18n.locale.startsWith('fr') ? ',' : '.'}
                            classNames={{input: classes.input, label: classes.label}}
                            value={draft.price}
                            error={errors.price}
                            onChange={(value) => update({price: value === '' ? '' : Number(value)})}
                        />
                    )}
                    <NumberInput
                        label={t`Number of places`}
                        placeholder={t`Unlimited`}
                        size="lg"
                        radius="xl"
                        min={1}
                        hideControls
                        allowDecimal={false}
                        classNames={{input: classes.input, label: classes.label}}
                        value={draft.quantity}
                        onChange={(value) => update({quantity: value === '' ? '' : Number(value)})}
                    />
                </div>

                <div>
                    <Textarea
                        label={t`A few words to make people want to come`}
                        description={t`Optional.`}
                        autosize
                        minRows={4}
                        maxRows={12}
                        radius="lg"
                        size="lg"
                        classNames={{input: classes.textarea, label: classes.label, description: classes.fieldHint}}
                        value={draft.description}
                        maxLength={5000}
                        onChange={(event) => update({description: event.currentTarget.value})}
                    />
                    {aiEnabled && (
                        <div className={classes.aiRow}>
                            <span className={classes.aiHint}>
                                {previousDescription !== null ? (
                                    <button type="button" className={classes.skipLink} onClick={() => {
                                        update({description: previousDescription});
                                        setPreviousDescription(null);
                                    }}>
                                        <Trans>Go back to my text</Trans>
                                    </button>
                                ) : <Trans>Write a few lines, the AI turns them into a clear description.</Trans>}
                            </span>
                            <Button type="button" radius="xl" className={classes.aiButton}
                                    leftSection={<IconSparkles size={18}/>}
                                    loading={improveDescription.isPending}
                                    disabled={draft.description.trim().length < 10}
                                    onClick={handleImprove}>
                                <Trans>Improve with AI</Trans>
                            </Button>
                        </div>
                    )}
                </div>

                {organizers.length > 1 && (
                    <Select
                        label={t`Organized by`}
                        size="lg"
                        radius="xl"
                        classNames={{input: classes.input, label: classes.label}}
                        value={organizerId}
                        error={errors.organizer}
                        onChange={(value) => {
                            setOrganizerId(value);
                            setErrors((current) => ({...current, organizer: undefined}));
                        }}
                        data={organizers.map((item) => ({value: String(item.id), label: item.name}))}
                    />
                )}
            </div>

            {formError && <p className={classes.error}>{formError}</p>}

            <div className={classes.footer}>
                <button type="button" className={classes.backLink} onClick={() => goTo('essentials')}>
                    <IconArrowLeft size={18}/> <Trans>Back</Trans>
                </button>
                <div className={classes.submitBlock}>
                    <Button type="submit" size="lg" radius="xl" className={classes.primaryButton}
                            loading={createEvent.isPending} rightSection={<IconArrowRight size={18}/>}>
                        <Trans>Create my event</Trans>
                    </Button>
                    <span className={classes.submitHint}>
                        <Trans>It stays a draft until you publish it.</Trans>
                    </span>
                </div>
            </div>
        </>
    );

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <NavLink to={dashboardUrl} className={classes.backLink}>
                    <IconArrowLeft size={18}/> <Trans>Dashboard</Trans>
                </NavLink>
                <span className={classes.stepCount}><Trans>Step {stepNumber} of 2</Trans></span>
            </header>
            <div className={classes.progress}>
                <div className={classes.progressBar} style={{width: step === 'details' ? '100%' : '50%'}}/>
            </div>
            <main className={classes.main}>
                <form key={step} className={classes.content} onSubmit={handleSubmit} noValidate>
                    {step === 'essentials' && renderEssentials()}
                    {step === 'details' && renderDetails()}
                </form>
            </main>
        </div>
    );
};

export default EventAssistant;
