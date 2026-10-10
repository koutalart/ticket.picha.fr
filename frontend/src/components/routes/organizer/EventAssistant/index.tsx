import {FormEvent, ReactNode, useEffect, useMemo, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {NavLink, useNavigate, useSearchParams} from "react-router";
import {Button, Loader, NumberInput, Select, Textarea, TextInput} from "@mantine/core";
import {Dropzone, FileRejection} from "@mantine/dropzone";
import {
    IconArrowLeft,
    IconArrowRight,
    IconCalendarEvent,
    IconPencil,
    IconPlus,
    IconSparkles,
    IconUpload,
    IconWand,
    IconX,
} from "@tabler/icons-react";
import {useGetOrganizers} from "../../../../queries/useGetOrganizers.ts";
import {useGetEventAssistantStatus} from "../../../../queries/useGetEventAssistantStatus.ts";
import {useReadEventPoster} from "../../../../mutations/useReadEventPoster.ts";
import {useImproveEventDescription} from "../../../../mutations/useImproveEventDescription.ts";
import {useCreateEventFromAssistant} from "../../../../mutations/useCreateEventFromAssistant.ts";
import {EventDraft} from "../../../../api/event-assistant.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../types.ts";
import classes from "./EventAssistant.module.scss";

type Mode = 'poster' | 'manual';
type StepId = 'start' | 'poster' | 'title' | 'date' | 'venue' | 'description' | 'price' | 'cover' | 'review';

interface Draft {
    mode: Mode | null;
    title: string;
    startDate: string;
    hasEndDate: boolean;
    endDate: string;
    venue: string;
    description: string;
    price: number | '';
    quantity: number | '';
}

const EMPTY_DRAFT: Draft = {
    mode: null,
    title: '',
    startDate: '',
    hasEndDate: false,
    endDate: '',
    venue: '',
    description: '',
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

const formatPrice = (price: number) => new Intl.NumberFormat(i18n.locale, {style: 'currency', currency: 'EUR'}).format(price);

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
    const [error, setError] = useState<string | null>(null);
    const [aiNotice, setAiNotice] = useState<string | null>(null);

    const initialMode = searchParams.get('mode');
    const [step, setStep] = useState<StepId>(() => {
        if (initialMode === 'poster') return 'poster';
        if (initialMode === 'manual') return 'title';
        return 'start';
    });

    useEffect(() => {
        const stored = readStoredDraft(searchParams.get('organizer') ?? undefined);
        setDraft(initialMode === 'poster' || initialMode === 'manual' ? {...stored, mode: initialMode} : stored);
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

    const steps: StepId[] = useMemo(() => {
        const flow: StepId[] = ['start'];
        if (draft.mode === 'poster') flow.push('poster');
        flow.push('title', 'date', 'venue', 'description', 'price');
        if (draft.mode !== 'poster') flow.push('cover');
        flow.push('review');
        return flow;
    }, [draft.mode]);

    const position = Math.max(0, steps.indexOf(step));
    const progress = steps.length > 1 ? position / (steps.length - 1) : 0;

    const update = (values: Partial<Draft>) => {
        setError(null);
        setDraft((current) => ({...current, ...values}));
    };

    const goTo = (target: StepId) => {
        setError(null);
        setStep(target);
    };

    const next = () => goTo(steps[Math.min(position + 1, steps.length - 1)]);
    const back = () => goTo(steps[Math.max(position - 1, 0)]);

    const choose = (mode: Mode) => {
        update({mode});
        goTo(mode === 'poster' ? 'poster' : 'title');
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
            price: found.price ?? current.price,
        }));
    };

    const handlePoster = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        setPoster(file);
        setPosterPreview(URL.createObjectURL(file));
        setAiNotice(null);

        if (!aiEnabled) {
            goTo('title');
            return;
        }

        readPoster.mutate({poster: file, timezone: organizer?.timezone}, {
            onSuccess: ({data}) => {
                applyDraft(data);
                setAiNotice(t`We filled in what we could read on your poster. Check each answer before continuing.`);
                goTo('title');
            },
            onError: () => {
                setAiNotice(t`We couldn't read your poster. Your visual is kept: fill in the details yourself.`);
                goTo('title');
            },
        });
    };

    const rejectPoster = (rejections: FileRejection[]) => {
        const tooBig = rejections.some((rejection) => rejection.errors.some((item) => item.code === 'file-too-large'));
        setError(tooBig ? t`The image must not be larger than 8 MB.` : t`Use a JPG, PNG, WebP or HEIC image.`);
    };

    const removePoster = () => {
        setPoster(null);
        setPosterPreview(null);
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
            onError: () => showError(t`The assistant could not answer. Please try again or fill in the form yourself.`),
        });
    };

    const validate = (current: StepId): string | null => {
        switch (current) {
            case 'title':
                if (!draft.title.trim()) return t`Give your event a title.`;
                if (draft.title.trim().length > 150) return t`The title must be 150 characters or fewer.`;
                return null;
            case 'date':
                if (!draft.startDate) return t`Choose when your event starts.`;
                if (draft.hasEndDate && draft.endDate && draft.endDate <= draft.startDate) return t`The end must be after the start.`;
                return null;
            case 'price':
                if (draft.price === '' || Number(draft.price) < 0) return t`Enter a price, or 0 for a free event.`;
                return null;
            default:
                return null;
        }
    };

    const submitStep = (event: FormEvent) => {
        event.preventDefault();
        const message = validate(step);
        if (message) {
            setError(message);
            return;
        }
        if (step === 'review') {
            handleCreate();
            return;
        }
        next();
    };

    const handleCreate = () => {
        if (!organizer) {
            setError(t`Choose the organizer of this event.`);
            return;
        }
        createEvent.mutate({
            payload: {
                organizer_id: organizer.id as IdParam,
                title: draft.title.trim(),
                start_date: toApiDate(draft.startDate),
                end_date: draft.hasEndDate && draft.endDate ? toApiDate(draft.endDate) : null,
                venue: draft.venue.trim() || null,
                description: draft.description.trim() || null,
                price: Number(draft.price),
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
                    showError(t`Your event is created, but the visual could not be added. Add it from the event settings.`);
                }
                navigate(`/manage/event/${event.id}/getting-started?new_event=true`);
            },
            onError: () => setError(t`Your event could not be created. Check your answers and try again.`),
        });
    };

    const dashboardUrl = organizer ? `/manage/organizer/${organizer.id}/dashboard` : '/manage/events';
    const endValue = draft.hasEndDate ? draft.endDate : null;

    const footer = (continueLabel: ReactNode = <Trans>Continue</Trans>, canSkip = false) => (
        <div className={classes.footer}>
            {position > 0 ? (
                <button type="button" className={classes.backLink} onClick={back}>
                    <IconArrowLeft size={18}/> <Trans>Back</Trans>
                </button>
            ) : <span/>}
            <div className={classes.footerActions}>
                {canSkip && (
                    <button type="button" className={classes.skipLink} onClick={next}><Trans>Skip</Trans></button>
                )}
                <Button type="submit" size="lg" radius="xl" className={classes.primaryButton}
                        loading={createEvent.isPending} rightSection={<IconArrowRight size={18}/>}>
                    {continueLabel}
                </Button>
            </div>
        </div>
    );

    const renderStep = () => {
        switch (step) {
            case 'start':
                return (
                    <>
                        <h1 className={classes.title}><Trans>Create your event.</Trans></h1>
                        <p className={classes.subtitle}>
                            <Trans>Choose how to start. Your answers are saved as you go.</Trans>
                        </p>
                        <div className={classes.choices}>
                            <button type="button" className={classes.choiceCard} onClick={() => choose('poster')}>
                                {aiEnabled && (
                                    <span className={classes.badge}><IconSparkles size={14}/> <Trans>Recommended</Trans></span>
                                )}
                                <IconWand size={34} stroke={1.6}/>
                                <span className={classes.choiceTitle}><Trans>I have a visual</Trans></span>
                                <span className={classes.choiceText}>
                                    {aiEnabled
                                        ? <Trans>Upload your poster, the AI pre-fills everything. You check each step.</Trans>
                                        : <Trans>Upload your poster, then answer a few simple questions.</Trans>}
                                </span>
                            </button>
                            <button type="button" className={classes.choiceCard} onClick={() => choose('manual')}>
                                <IconPencil size={34} stroke={1.6}/>
                                <span className={classes.choiceTitle}><Trans>I'll fill it in myself</Trans></span>
                                <span className={classes.choiceText}>
                                    <Trans>A few simple questions, one at a time. No visual needed.</Trans>
                                </span>
                            </button>
                        </div>
                    </>
                );

            case 'poster':
            case 'cover':
                return (
                    <>
                        <h1 className={classes.title}>
                            {step === 'poster' ? <Trans>Upload your visual.</Trans> : <Trans>Add a visual</Trans>}
                        </h1>
                        <p className={classes.subtitle}>
                            {step === 'poster' && aiEnabled
                                ? <Trans>The AI reads the poster and pre-fills the form. You can check and change everything afterwards.</Trans>
                                : <Trans>It will be the cover of your event page. (Optional)</Trans>}
                        </p>
                        {readPoster.isPending ? (
                            <div className={classes.reading}>
                                {posterPreview && <img src={posterPreview} alt="" className={classes.readingPreview}/>}
                                <Loader size="md"/>
                                <p><Trans>Reading your poster…</Trans></p>
                            </div>
                        ) : posterPreview && step === 'cover' ? (
                            <div className={classes.posterKept}>
                                <img src={posterPreview} alt=""/>
                                <button type="button" className={classes.skipLink} onClick={removePoster}>
                                    <IconX size={16}/> <Trans>Remove</Trans>
                                </button>
                            </div>
                        ) : (
                            <Dropzone
                                onDrop={step === 'poster' ? handlePoster : (files) => {
                                    if (files[0]) {
                                        setPoster(files[0]);
                                        setPosterPreview(URL.createObjectURL(files[0]));
                                    }
                                }}
                                onReject={rejectPoster}
                                accept={POSTER_TYPES}
                                maxSize={MAX_POSTER_SIZE}
                                multiple={false}
                                className={classes.dropzone}
                            >
                                <div className={classes.dropzoneInner}>
                                    <IconUpload size={40} stroke={1.5} className={classes.dropzoneIcon}/>
                                    <span className={classes.dropzoneTitle}><Trans>Drop your image here</Trans></span>
                                    <span className={classes.dropzoneHint}><Trans>JPG, PNG, WebP or HEIC — 8 MB max</Trans></span>
                                    <span className={classes.fakeButton}><IconUpload size={18}/> <Trans>Choose a file</Trans></span>
                                </div>
                            </Dropzone>
                        )}
                        {error && <p className={classes.error}>{error}</p>}
                        {step === 'poster' ? (
                            <div className={classes.footer}>
                                <button type="button" className={classes.backLink} onClick={() => choose('manual')}>
                                    <IconArrowLeft size={18}/> <Trans>No visual, I'll fill it in</Trans>
                                </button>
                            </div>
                        ) : footer(<Trans>Continue</Trans>, !poster)}
                    </>
                );

            case 'title':
                return (
                    <>
                        {aiNotice && <p className={classes.notice}><IconSparkles size={16}/> {aiNotice}</p>}
                        <h1 className={classes.title}><Trans>What is the title of your event?</Trans></h1>
                        <p className={classes.subtitle}><Trans>The name your guests will see.</Trans></p>
                        <TextInput
                            data-autofocus
                            autoFocus
                            size="xl"
                            radius="xl"
                            classNames={{input: classes.input}}
                            value={draft.title}
                            maxLength={150}
                            placeholder={t`Open day of Mayotte la 1ère`}
                            onChange={(event) => update({title: event.currentTarget.value})}
                        />
                        {error && <p className={classes.error}>{error}</p>}
                        {footer()}
                    </>
                );

            case 'date':
                return (
                    <>
                        <h1 className={classes.title}><Trans>When does your event take place?</Trans></h1>
                        <p className={classes.subtitle}><Trans>Start date and time.</Trans></p>
                        <TextInput
                            autoFocus
                            type="datetime-local"
                            size="xl"
                            radius="xl"
                            classNames={{input: classes.input}}
                            value={draft.startDate}
                            onChange={(event) => update({startDate: event.currentTarget.value})}
                        />
                        {draft.hasEndDate ? (
                            <div className={classes.endDate}>
                                <TextInput
                                    type="datetime-local"
                                    size="lg"
                                    radius="xl"
                                    label={t`Ends`}
                                    classNames={{input: classes.input}}
                                    value={draft.endDate}
                                    min={draft.startDate || undefined}
                                    onChange={(event) => update({endDate: event.currentTarget.value})}
                                />
                                <button type="button" className={classes.skipLink} onClick={() => update({hasEndDate: false, endDate: ''})}>
                                    <IconX size={16}/> <Trans>Remove the end date</Trans>
                                </button>
                            </div>
                        ) : (
                            <button type="button" className={classes.addLink} onClick={() => update({hasEndDate: true})}>
                                <IconPlus size={18}/> <Trans>Add an end date</Trans>
                            </button>
                        )}
                        {draft.startDate && (
                            <p className={classes.datePreview}>
                                <IconCalendarEvent size={20}/> {describeDates(draft.startDate, endValue)}
                            </p>
                        )}
                        {error && <p className={classes.error}>{error}</p>}
                        {footer()}
                    </>
                );

            case 'venue':
                return (
                    <>
                        <h1 className={classes.title}><Trans>Where is it happening?</Trans></h1>
                        <p className={classes.subtitle}><Trans>Venue, town or full address. (Optional)</Trans></p>
                        <TextInput
                            autoFocus
                            size="xl"
                            radius="xl"
                            classNames={{input: classes.input}}
                            value={draft.venue}
                            maxLength={255}
                            placeholder={t`Le 5/5, Mamoudzou`}
                            onChange={(event) => update({venue: event.currentTarget.value})}
                        />
                        {footer(<Trans>Continue</Trans>, !draft.venue)}
                    </>
                );

            case 'description':
                return (
                    <>
                        <h1 className={classes.title}><Trans>Describe your event</Trans></h1>
                        <p className={classes.subtitle}><Trans>Make people want to come, in a few lines. (Optional)</Trans></p>
                        <Textarea
                            autoFocus
                            autosize
                            minRows={5}
                            maxRows={14}
                            radius="lg"
                            size="lg"
                            classNames={{input: classes.textarea}}
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
                                            <Trans>Undo the rewrite</Trans>
                                        </button>
                                    ) : <Trans>The AI rewrites your text into a clear description, good for search engines.</Trans>}
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
                        {footer(<Trans>Continue</Trans>, !draft.description)}
                    </>
                );

            case 'price':
                return (
                    <>
                        <h1 className={classes.title}><Trans>Price and quantity</Trans></h1>
                        <p className={classes.subtitle}><Trans>Enter 0 € for a free event.</Trans></p>
                        <div className={classes.priceGrid}>
                            <NumberInput
                                autoFocus
                                label={t`Price (€)`}
                                size="xl"
                                radius="xl"
                                min={0}
                                decimalScale={2}
                                decimalSeparator={i18n.locale.startsWith('fr') ? ',' : '.'}
                                classNames={{input: classes.input, label: classes.label}}
                                value={draft.price}
                                onChange={(value) => update({price: value === '' ? '' : Number(value)})}
                            />
                            <NumberInput
                                label={t`Quantity`}
                                size="xl"
                                radius="xl"
                                min={1}
                                allowDecimal={false}
                                placeholder={t`Unlimited`}
                                classNames={{input: classes.input, label: classes.label}}
                                value={draft.quantity}
                                onChange={(value) => update({quantity: value === '' ? '' : Number(value)})}
                            />
                        </div>
                        {error && <p className={classes.error}>{error}</p>}
                        {footer()}
                    </>
                );

            case 'review': {
                const price = Number(draft.price);
                const rows: { label: string, value: ReactNode, target: StepId }[] = [
                    {label: t`Title`, value: draft.title, target: 'title'},
                    {label: t`Date`, value: describeDates(draft.startDate, endValue), target: 'date'},
                    {label: t`Venue`, value: draft.venue || <em>{t`Not specified`}</em>, target: 'venue'},
                    {
                        label: t`Description`,
                        value: draft.description ? <span className={classes.reviewDescription}>{draft.description}</span> : <em>{t`Not specified`}</em>,
                        target: 'description',
                    },
                    {
                        label: t`Ticket`,
                        value: `${price > 0 ? formatPrice(price) : t`Free`} · ${draft.quantity === '' ? t`unlimited` : t`${draft.quantity} places`}`,
                        target: 'price',
                    },
                ];
                return (
                    <>
                        <h1 className={classes.title}><Trans>Everything looks right?</Trans></h1>
                        <p className={classes.subtitle}>
                            <Trans>Your event is created as a draft: you can still change everything before publishing it.</Trans>
                        </p>
                        <div className={classes.review}>
                            {posterPreview && <img src={posterPreview} alt="" className={classes.reviewPoster}/>}
                            <dl className={classes.reviewList}>
                                {rows.map((row) => (
                                    <div key={row.label} className={classes.reviewRow}>
                                        <dt>{row.label}</dt>
                                        <dd>{row.value}</dd>
                                        <button type="button" className={classes.editLink} onClick={() => goTo(row.target)}>
                                            <Trans>Edit</Trans>
                                        </button>
                                    </div>
                                ))}
                            </dl>
                        </div>
                        {organizers.length > 1 && (
                            <Select
                                label={t`Organizer`}
                                size="lg"
                                radius="xl"
                                className={classes.organizerSelect}
                                value={organizerId}
                                onChange={setOrganizerId}
                                data={organizers.map((item) => ({value: String(item.id), label: item.name}))}
                            />
                        )}
                        {error && <p className={classes.error}>{error}</p>}
                        {footer(<Trans>Create my event</Trans>)}
                    </>
                );
            }
        }
    };

    return (
        <div className={classes.page}>
            <header className={classes.header}>
                <NavLink to={dashboardUrl} className={classes.backLink}>
                    <IconArrowLeft size={18}/> <Trans>Dashboard</Trans>
                </NavLink>
                {step !== 'start' && (
                    <span className={classes.stepCount}>
                        <Trans>Step {position} of {steps.length - 1}</Trans>
                    </span>
                )}
            </header>
            <div className={classes.progress}>
                <div className={classes.progressBar} style={{width: `${progress * 100}%`}}/>
            </div>
            <main className={classes.main}>
                <form key={step} className={step === 'start' ? classes.wide : classes.content} onSubmit={submitStep}>
                    {renderStep()}
                </form>
            </main>
        </div>
    );
};

export default EventAssistant;
