import {FormEvent, useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {NavLink, useNavigate, useSearchParams} from "react-router";
import {Button, Loader, NumberInput, SegmentedControl, Select, Textarea, TextInput} from "@mantine/core";
import {Dropzone, FileRejection} from "@mantine/dropzone";
import {IconArrowLeft, IconCalendarEvent, IconCheck, IconPlus, IconSparkles, IconUpload, IconX} from "@tabler/icons-react";
import {useGetOrganizers} from "../../../../queries/useGetOrganizers.ts";
import {useGetEventAssistantStatus} from "../../../../queries/useGetEventAssistantStatus.ts";
import {useReadEventPoster} from "../../../../mutations/useReadEventPoster.ts";
import {useImproveEventDescription} from "../../../../mutations/useImproveEventDescription.ts";
import {useCreateEventFromAssistant} from "../../../../mutations/useCreateEventFromAssistant.ts";
import {useResearchEventDescription} from "../../../../mutations/useResearchEventDescription.ts";
import {EventCategories} from "../../../../constants/eventCategories.ts";
import {EventDraft} from "../../../../api/event-assistant.client.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {IdParam} from "../../../../types.ts";
import classes from "./EventAssistant.module.scss";

type StepId = 'upload' | 'review';
type FieldErrors = Partial<Record<'title' | 'startDate' | 'endDate' | 'price' | 'organizer', string>>;

interface Draft {
    title: string;
    category: string | null;
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
    category: null,
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
    const researchDescription = useResearchEventDescription();

    const organizers = organizersQuery.data?.data ?? [];
    const [organizerId, setOrganizerId] = useState<string | null>(searchParams.get('organizer'));
    const organizer = organizers.find((item) => String(item.id) === organizerId) ?? (organizers.length === 1 ? organizers[0] : undefined);
    const aiEnabled = statusQuery.data?.ai_enabled === true;

    const [step, setStep] = useState<StepId>('upload');
    const [draft, setDraft] = useState<Draft>(EMPTY_DRAFT);
    const [filledByAi, setFilledByAi] = useState(false);
    const [poster, setPoster] = useState<File | null>(null);
    const [posterPreview, setPosterPreview] = useState<string | null>(null);
    const [previousDescription, setPreviousDescription] = useState<string | null>(null);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        if (!organizerId && organizers.length === 1) {
            setOrganizerId(String(organizers[0].id));
        }
    }, [organizers.length]);

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

    const showReview = () => {
        setErrors({});
        setFormError(null);
        setStep('review');
        if (typeof window !== 'undefined') window.scrollTo({top: 0});
    };

    const applyAiDraft = (found: EventDraft) => {
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
            category: found.category ?? current.category,
            quantity: found.quantity ?? current.quantity,
        }));
    };

    // The poster gives the facts; the AI then searches the web to write a richer description.
    const writeDescription = (found: EventDraft) => {
        if (!found.title) return;
        researchDescription.mutate({
            title: found.title,
            venue: found.venue,
            category: found.category,
            facts: found.facts,
            draft: found.description,
        }, {
            onSuccess: ({data}) => setDraft((current) => (
                current.description === (found.description ?? '') ? {...current, description: data.description} : current
            )),
        });
    };

    const handlePoster = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        setPoster(file);
        setPosterPreview(URL.createObjectURL(file));
        setFormError(null);

        if (!aiEnabled) {
            setFilledByAi(false);
            showReview();
            return;
        }

        readPoster.mutate({poster: file, timezone: organizer?.timezone}, {
            onSuccess: ({data}) => {
                applyAiDraft(data);
                setFilledByAi(true);
                showReview();
                writeDescription(data);
            },
            onError: () => {
                setFilledByAi(false);
                showError(t`We couldn't read your poster. It's kept as your cover: just fill in the details.`);
                showReview();
            },
        });
    };

    const rejectPoster = (rejections: FileRejection[]) => {
        const tooBig = rejections.some((rejection) => rejection.errors.some((item) => item.code === 'file-too-large'));
        setFormError(tooBig ? t`This image is over 8 MB. Try a lighter one.` : t`We accept JPG, PNG, WebP and HEIC images.`);
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
            onError: () => showError(t`The AI couldn't rewrite your text this time. Try again in a moment.`),
        });
    };

    const validate = (): FieldErrors => {
        const found: FieldErrors = {};
        if (!draft.title.trim()) found.title = t`Your event needs a name.`;
        if (!draft.startDate) found.startDate = t`Tell us when it starts.`;
        if (draft.hasEndDate && draft.endDate && draft.startDate && draft.endDate <= draft.startDate) {
            found.endDate = t`The end has to come after the start.`;
        }
        if (!draft.isFree && (draft.price === '' || Number(draft.price) <= 0)) {
            found.price = t`Enter a ticket price, or choose "Free".`;
        }
        if (!organizer) found.organizer = t`Choose who is organizing this event.`;
        return found;
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        const found = validate();
        setErrors(found);
        if (Object.keys(found).length > 0 || !organizer) {
            setFormError(t`A few details are missing, they're marked in red.`);
            return;
        }

        createEvent.mutate({
            payload: {
                organizer_id: organizer.id as IdParam,
                title: draft.title.trim(),
                category: draft.category,
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

    const renderUpload = () => (
        <>
            <h1 className={classes.title}><Trans>Create your event.</Trans></h1>
            <p className={classes.subtitle}>
                {aiEnabled
                    ? <Trans>Drop your poster: we read it and fill in everything for you.</Trans>
                    : <Trans>Start with your poster, it will be the cover of your event.</Trans>}
            </p>

            {readPoster.isPending ? (
                <div className={classes.reading}>
                    {posterPreview && <img src={posterPreview} alt="" className={classes.readingPreview}/>}
                    <div className={classes.readingText}>
                        <Loader size="sm"/>
                        <span><Trans>Reading your poster…</Trans></span>
                    </div>
                </div>
            ) : (
                <Dropzone onDrop={handlePoster} onReject={rejectPoster} accept={POSTER_TYPES} maxSize={MAX_POSTER_SIZE}
                          multiple={false} className={classes.dropzone}>
                    <div className={classes.dropzoneInner}>
                        <IconUpload size={40} stroke={1.5} className={classes.dropzoneIcon}/>
                        <span className={classes.dropzoneTitle}><Trans>Upload your poster here</Trans></span>
                        <span className={classes.dropzoneHint}><Trans>Drag it in, or</Trans></span>
                        <span className={classes.fakeButton}><Trans>Choose a file</Trans></span>
                        <span className={classes.dropzoneHint}><Trans>JPG, PNG, WebP or HEIC, up to 8 MB</Trans></span>
                    </div>
                </Dropzone>
            )}

            {formError && <p className={classes.error}>{formError}</p>}

            {!readPoster.isPending && (
                <button type="button" className={classes.noPosterLink} onClick={() => {
                    setFilledByAi(false);
                    showReview();
                }}>
                    <Trans>I don't have a poster yet</Trans>
                </button>
            )}
        </>
    );

    const renderReview = () => (
        <>
            <h1 className={classes.title}>
                {filledByAi ? <Trans>Is everything right?</Trans> : <Trans>Your event</Trans>}
            </h1>
            <p className={classes.subtitle}>
                {filledByAi
                    ? <Trans>We filled this in from your poster. Correct anything that's off, then confirm.</Trans>
                    : <Trans>Fill in the essentials. You can complete the rest later.</Trans>}
            </p>

            <div className={classes.review}>
                {posterPreview && (
                    <div className={classes.posterColumn}>
                        <img src={posterPreview} alt="" className={classes.posterImage}/>
                        <button type="button" className={classes.smallLink} onClick={removePoster}>
                            <IconX size={14}/> <Trans>Remove the poster</Trans>
                        </button>
                    </div>
                )}

                <div className={classes.fields}>
                    <TextInput
                        label={t`Event name`}
                        size="md"
                        radius="lg"
                        classNames={{input: classes.input, label: classes.label}}
                        value={draft.title}
                        maxLength={150}
                        placeholder={t`E.g. Open day at Mayotte la 1ère`}
                        error={errors.title}
                        onChange={(event) => update({title: event.currentTarget.value})}
                    />

                    <Select
                        label={t`Type of event`}
                        placeholder={t`Choose a type`}
                        size="md"
                        radius="lg"
                        searchable
                        clearable
                        classNames={{input: classes.input, label: classes.label}}
                        value={draft.category}
                        onChange={(value) => update({category: value})}
                        data={EventCategories.map((category) => ({value: category.id, label: `${category.emoji} ${category.name}`}))}
                    />

                    <div>
                        <div className={classes.twoColumns}>
                            <TextInput
                                type="datetime-local"
                                label={t`Starts`}
                                size="md"
                                radius="lg"
                                classNames={{input: classes.input, label: classes.label}}
                                value={draft.startDate}
                                error={errors.startDate}
                                onChange={(event) => update({startDate: event.currentTarget.value})}
                            />
                            {draft.hasEndDate ? (
                                <TextInput
                                    type="datetime-local"
                                    label={t`Ends`}
                                    size="md"
                                    radius="lg"
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
                            ) : (
                                <button type="button" className={classes.addEndButton} onClick={() => update({hasEndDate: true})}>
                                    <IconPlus size={16}/> <Trans>Add an end time</Trans>
                                </button>
                            )}
                        </div>
                        {draft.startDate && (
                            <p className={classes.datePreview}>
                                <IconCalendarEvent size={16}/> {describeDates(draft.startDate, endValue)}
                            </p>
                        )}
                    </div>

                    <TextInput
                        label={t`Where?`}
                        size="md"
                        radius="lg"
                        classNames={{input: classes.input, label: classes.label}}
                        value={draft.venue}
                        maxLength={255}
                        placeholder={t`E.g. Le 5/5, Mamoudzou`}
                        onChange={(event) => update({venue: event.currentTarget.value})}
                    />

                    <div>
                        <span className={classes.label}><Trans>Entry</Trans></span>
                        <div className={classes.twoColumns}>
                            <SegmentedControl
                                fullWidth
                                radius="lg"
                                className={classes.segmented}
                                classNames={{indicator: classes.segmentedIndicator, label: classes.segmentedLabel}}
                                value={draft.isFree ? 'free' : 'paid'}
                                onChange={(value) => update({isFree: value === 'free'})}
                                data={[{value: 'paid', label: t`Paid`}, {value: 'free', label: t`Free`}]}
                            />
                            {!draft.isFree && (
                                <NumberInput
                                    aria-label={t`Ticket price (€)`}
                                    placeholder={t`Price in €`}
                                    size="md"
                                    radius="lg"
                                    min={0}
                                    hideControls
                                    decimalScale={2}
                                    decimalSeparator={i18n.locale.startsWith('fr') ? ',' : '.'}
                                    suffix=" €"
                                    classNames={{input: classes.input}}
                                    value={draft.price}
                                    error={errors.price}
                                    onChange={(value) => update({price: value === '' ? '' : Number(value)})}
                                />
                            )}
                        </div>
                    </div>

                    <NumberInput
                        label={t`Number of places`}
                        placeholder={t`Unlimited`}
                        size="md"
                        radius="lg"
                        min={1}
                        hideControls
                        allowDecimal={false}
                        classNames={{input: classes.input, label: classes.label}}
                        value={draft.quantity}
                        onChange={(value) => update({quantity: value === '' ? '' : Number(value)})}
                    />

                    <div>
                        {researchDescription.isPending && (
                            <p className={classes.writing}><Loader size="xs"/> <Trans>The AI is looking up your event and writing the description…</Trans></p>
                        )}
                        <Textarea
                            label={t`Description`}
                            placeholder={t`A few words to make people want to come (optional)`}
                            autosize
                            minRows={3}
                            maxRows={10}
                            radius="lg"
                            size="md"
                            classNames={{input: classes.textarea, label: classes.label}}
                            value={draft.description}
                            maxLength={5000}
                            onChange={(event) => update({description: event.currentTarget.value})}
                        />
                        {aiEnabled && (
                            <div className={classes.aiRow}>
                                {previousDescription !== null && (
                                    <button type="button" className={classes.smallLink} onClick={() => {
                                        update({description: previousDescription});
                                        setPreviousDescription(null);
                                    }}>
                                        <Trans>Go back to my text</Trans>
                                    </button>
                                )}
                                <Button type="button" variant="subtle" radius="xl" className={classes.aiButton}
                                        leftSection={<IconSparkles size={16}/>}
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
                            size="md"
                            radius="lg"
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
            </div>

            {formError && <p className={classes.error}>{formError}</p>}

            <div className={classes.footer}>
                <button type="button" className={classes.backLink} onClick={() => setStep('upload')}>
                    <IconArrowLeft size={18}/> <Trans>Back</Trans>
                </button>
                <div className={classes.submitBlock}>
                    <Button type="submit" size="lg" radius="xl" className={classes.primaryButton}
                            loading={createEvent.isPending} leftSection={<IconCheck size={18}/>}>
                        {filledByAi ? <Trans>It's right, create the event</Trans> : <Trans>Create the event</Trans>}
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
            </header>
            <main className={classes.main}>
                <form key={step} className={step === 'upload' ? classes.contentNarrow : classes.content}
                      onSubmit={handleSubmit} noValidate>
                    {step === 'upload' ? renderUpload() : renderReview()}
                </form>
            </main>
        </div>
    );
};

export default EventAssistant;
