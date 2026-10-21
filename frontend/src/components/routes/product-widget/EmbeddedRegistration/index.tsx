import {FormEvent, KeyboardEvent, ReactNode, RefObject, useEffect, useMemo, useRef, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {useNavigate} from "react-router";
import {useMutation, useQuery} from "@tanstack/react-query";
import {useForm} from "@mantine/form";
import {ActionIcon, Button, Checkbox, Radio, Select, Textarea, TextInput} from "@mantine/core";
import {IconArrowLeft, IconArrowRight, IconCheck, IconCornerDownLeft, IconPlus, IconTicket, IconX} from "@tabler/icons-react";
import classNames from "classnames";
import {Event, Question, QuestionType} from "../../../../types.ts";
import {FinaliseOrderPayload, ProductFormPayload} from "../../../../api/order.client.ts";
import {eventsClientPublic} from "../../../../api/event.client.ts";
import {promoCodeClientPublic} from "../../../../api/promo-code.client.ts";
import {useGetEventQuestionsPublic} from "../../../../queries/useGetEventQuestionsPublic.ts";
import {
    EmbeddedRegistrationError,
    PendingEmbeddedOrder,
    useCompleteEmbeddedRegistration
} from "../../../../mutations/useCompleteEmbeddedRegistration.ts";
import {
    EMAIL_PATTERN,
    getEventProducts,
    hasUnsupportedQuestions,
    isAnswerEmpty,
    isSingleConsentQuestion,
    PHONE_PATTERN,
    resolveEmbeddedRegistrationTicket,
    sortQuestions,
} from "../../../../utilites/embeddedRegistration.ts";
import {getSessionIdentifier} from "../../../../utilites/sessionIdentifier.ts";
import {getOrderTrackingContext, getQrLinkCode} from "../../../../utilites/qrAttribution.ts";
import {addQueryStringToUrl, removeQueryStringFromUrl} from "../../../../utilites/helpers.ts";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {AnalyticsEvents, trackEvent} from "../../../../utilites/analytics.ts";
import {getConfig} from "../../../../utilites/config.ts";
import {getAppName, getTermsOfSaleUrl} from "../../../../utilites/branding.ts";
import {UserGeneratedContent} from "../../../common/UserGeneratedContent";
import classes from "./EmbeddedRegistration.module.scss";

type StepKind = 'name' | 'email' | 'phone' | 'questions' | 'participants' | 'confirm';

interface Step {
    id: string;
    kind: StepKind;
    questionIndexes: number[];
    consentIndexes: number[];
}

interface QuestionAnswerValue {
    question_id?: number;
    response: { answer: string | string[] };
}

interface ParticipantValue {
    key: string;
    first_name: string;
    last_name: string;
    birth_date: string;
    questions: QuestionAnswerValue[];
}

interface RegistrationFormValues {
    order: {
        first_name: string;
        last_name: string;
        email: string;
        email_confirmation: string;
        questions: QuestionAnswerValue[];
        opted_into_marketing: boolean;
    };
    products: ParticipantValue[];
}

interface EmbeddedRegistrationProps {
    event: Event;
    promoCode?: string;
    promoCodeValid?: boolean;
    sectionRef?: RefObject<HTMLElement>;
    renderFallback: () => ReactNode;
}

const AFFILIATE_EXPIRY_DAYS = 30;
const STEP_TRANSITION_MS = 660;

const readAffiliateCode = (eventId: unknown): string | null => {
    try {
        const fromUrl = new URLSearchParams(window.location.search).get('aff');
        if (fromUrl) {
            return fromUrl;
        }
        const stored = window.localStorage.getItem('affiliate_code_' + eventId);
        if (!stored) {
            return null;
        }
        const parsed = JSON.parse(stored);
        const ageInDays = (Date.now() - parsed.timestamp) / (1000 * 60 * 60 * 24);
        return ageInDays <= AFFILIATE_EXPIRY_DAYS ? parsed.code : null;
    } catch {
        return null;
    }
};

const emptyAnswer = (question: Question): QuestionAnswerValue => ({
    question_id: question.id,
    response: {answer: question.type === QuestionType.CHECKBOX ? [] : ''},
});

let participantKeySeed = 0;
const nextParticipantKey = () => `participant-${++participantKeySeed}`;

const productIdsOf = (event?: Event) => new Set(getEventProducts(event).map(product => Number(product.id)));

const prefersReducedMotion = () =>
    typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const fieldClassNames = {
    root: classes.field,
    label: classes.fieldLabel,
    description: classes.fieldDescription,
    input: classes.fieldInput,
    error: classes.fieldError,
    required: classes.fieldRequired,
};

const largeFieldClassNames = {
    ...fieldClassNames,
    label: classNames(classes.fieldLabel, classes.questionLabel),
};

export const EmbeddedRegistration = ({event: initialEvent, promoCode: initialPromoCode, promoCodeValid, sectionRef, renderFallback}: EmbeddedRegistrationProps) => {
    const navigate = useNavigate();
    const eventId = initialEvent.id;
    const startsWithCode = !!promoCodeValid && !!initialPromoCode;

    const [event, setEvent] = useState<Event>(initialEvent);
    const [promoCode, setPromoCode] = useState<string | null>(startsWithCode ? initialPromoCode! : null);
    const [publicProductIds, setPublicProductIds] = useState<Set<number> | null>(startsWithCode ? null : productIdsOf(initialEvent));
    const [stepIndex, setStepIndex] = useState(0);
    const [direction, setDirection] = useState<'forward' | 'backward'>('forward');
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [creationFailed, setCreationFailed] = useState(false);
    const [forceClassic, setForceClassic] = useState(false);
    const [pendingOrder, setPendingOrder] = useState<PendingEmbeddedOrder | null>(null);
    const [codeOpen, setCodeOpen] = useState(promoCodeValid === false);
    const [codeValue, setCodeValue] = useState('');
    const [codeError, setCodeError] = useState<string | null>(promoCodeValid === false ? t`This invitation code is not valid.` : null);
    const stepRef = useRef<HTMLDivElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const hasInteracted = useRef(false);
    const pendingFocus = useRef<'step' | 'error' | string | null>(null);
    const codeInputRef = useRef<HTMLInputElement>(null);
    const nextRef = useRef<() => void>(() => undefined);
    const leaveTimer = useRef<number | undefined>(undefined);
    const [leaving, setLeaving] = useState<{ step: Step, direction: 'forward' | 'backward' } | null>(null);

    useEffect(() => {
        if (codeOpen && hasInteracted.current) {
            codeInputRef.current?.focus();
        }
    }, [codeOpen]);

    const questionsQuery = useGetEventQuestionsPublic(eventId);
    const publicEventQuery = useQuery({
        queryKey: ['embeddedRegistrationPublicEvent', eventId],
        queryFn: async () => (await eventsClientPublic.findByID(eventId, null)).data,
        enabled: publicProductIds === null,
        refetchOnWindowFocus: false,
    });

    useEffect(() => {
        if (publicProductIds === null && publicEventQuery.data) {
            setPublicProductIds(productIdsOf(publicEventQuery.data));
        }
    }, [publicEventQuery.data]);

    const ticket = resolveEmbeddedRegistrationTicket(event, publicProductIds);
    const productId = ticket?.product.id;

    const orderQuestions = useMemo(
        () => sortQuestions((questionsQuery.data ?? []).filter(question => question.belongs_to === 'ORDER')),
        [questionsQuery.data]
    );
    const participantQuestions = useMemo(
        () => sortQuestions((questionsQuery.data ?? []).filter(question =>
            question.belongs_to === 'PRODUCT' && question.product_ids?.includes(Number(productId))
        )),
        [questionsQuery.data, productId]
    );

    const ageRestrictionRule = event.settings?.age_restriction_rule ?? 'NONE';
    const isAgeCheckEnabled = ageRestrictionRule === 'ACCOMPANIED_MINORS' || ageRestrictionRule === 'MINIMUM_AGE';
    const ageLimit = event.settings?.age_restriction_minimum_age ?? 18;
    const ageRestrictionHint = ageRestrictionRule === 'MINIMUM_AGE'
        ? t`This event is restricted to attendees aged ${ageLimit} or over.`
        : t`Attendees under ${ageLimit} must be accompanied by an adult registered in the same order.`;

    const makeParticipant = (): ParticipantValue => ({
        key: nextParticipantKey(),
        first_name: '',
        last_name: '',
        birth_date: '',
        questions: participantQuestions.map(emptyAnswer),
    });

    const form = useForm<RegistrationFormValues>({
        initialValues: {
            order: {
                first_name: '',
                last_name: '',
                email: '',
                email_confirmation: '',
                questions: [],
                opted_into_marketing: false,
            },
            products: [{key: 'participant-buyer', first_name: '', last_name: '', birth_date: '', questions: []}],
        },
    });

    useEffect(() => {
        const existing = form.values.order.questions;
        form.setFieldValue('order.questions', orderQuestions.map(question =>
            existing.find(answer => answer.question_id === question.id) ?? emptyAnswer(question)
        ));
    }, [orderQuestions]);

    useEffect(() => {
        if (!ticket) {
            return;
        }
        let participants = form.values.products.map(participant => ({
            ...participant,
            questions: participantQuestions.map(question =>
                participant.questions.find(answer => answer.question_id === question.id) ?? emptyAnswer(question)
            ),
        }));
        participants = participants.slice(0, ticket.maxParticipants);
        while (participants.length < ticket.minParticipants) {
            participants.push(makeParticipant());
        }
        form.setFieldValue('products', participants);
    }, [participantQuestions, ticket?.product.id, ticket?.maxParticipants, ticket?.minParticipants]);

    const steps = useMemo<Step[]>(() => {
        const result: Step[] = [
            {id: 'name', kind: 'name', questionIndexes: [], consentIndexes: []},
            {id: 'email', kind: 'email', questionIndexes: [], consentIndexes: []},
        ];
        let anchor = result[1];
        const phoneIndex = orderQuestions.findIndex(question => question.type === QuestionType.PHONE);
        if (phoneIndex >= 0) {
            anchor = {id: 'phone', kind: 'phone', questionIndexes: [phoneIndex], consentIndexes: []};
            result.push(anchor);
        }
        orderQuestions.forEach((question, index) => {
            if (index === phoneIndex) {
                return;
            }
            if (isSingleConsentQuestion(question)) {
                anchor.consentIndexes.push(index);
                return;
            }
            anchor = {id: `questions-${index}`, kind: 'questions', questionIndexes: [index], consentIndexes: []};
            result.push(anchor);
        });
        result.push({id: 'participants', kind: 'participants', questionIndexes: [], consentIndexes: []});
        result.push({id: 'confirm', kind: 'confirm', questionIndexes: [], consentIndexes: []});
        return result;
    }, [orderQuestions]);

    const safeStepIndex = Math.min(stepIndex, steps.length - 1);
    const step = steps[safeStepIndex];
    const isLastStep = safeStepIndex === steps.length - 1;

    const focusInStep = (mode: 'step' | 'error' | string) => {
        const container = stepRef.current;
        if (!container) {
            return;
        }
        let target: HTMLElement | null = null;
        if (mode === 'error') {
            target = container.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?? container.querySelector<HTMLElement>('.mantine-InputWrapper-error')
                    ?.closest('.mantine-InputWrapper-root')
                    ?.querySelector<HTMLElement>('input, textarea, [role="radio"], [role="checkbox"]') ?? null;
        } else if (mode !== 'step') {
            target = container.querySelector<HTMLElement>(`[data-focus-key="${mode}"]`);
        }
        target = target ?? container.querySelector<HTMLElement>(
            'input:not([type="hidden"]):not([disabled]), textarea, [role="radio"], [role="checkbox"]'
        ) ?? container;
        target.focus({preventScroll: true});

        const panel = panelRef.current;
        if (panel) {
            const rect = panel.getBoundingClientRect();
            if (rect.top < 0 || rect.top > window.innerHeight * 0.4) {
                panel.scrollIntoView({block: 'start', behavior: prefersReducedMotion() ? 'auto' : 'smooth'});
            }
        }
    };

    useEffect(() => {
        if (!pendingFocus.current) {
            return;
        }
        const mode = pendingFocus.current;
        pendingFocus.current = null;
        const frame = window.requestAnimationFrame(() => focusInStep(mode));
        return () => window.cancelAnimationFrame(frame);
    });

    const goToStep = (index: number, focus: 'step' | 'error' = 'step') => {
        hasInteracted.current = true;
        const target = Math.max(0, Math.min(index, steps.length - 1));
        const nextDirection = target >= safeStepIndex ? 'forward' : 'backward';
        if (target !== safeStepIndex && !prefersReducedMotion()) {
            window.clearTimeout(leaveTimer.current);
            setLeaving({step, direction: nextDirection});
            leaveTimer.current = window.setTimeout(() => setLeaving(null), STEP_TRANSITION_MS);
        }
        setDirection(nextDirection);
        setStepIndex(target);
        pendingFocus.current = focus;
    };

    useEffect(() => () => window.clearTimeout(leaveTimer.current), []);

    const requiredMessage = t`This field is required`;

    const validateQuestion = (question: Question, path: string, errors: Record<string, string>) => {
        const answer = form.getInputProps(`${path}.response.answer`).value;
        if (question.required && isAnswerEmpty(answer)) {
            errors[`${path}.response.answer`] = requiredMessage;
            return;
        }
        if (question.type === QuestionType.PHONE && !isAnswerEmpty(answer) && !PHONE_PATTERN.test(String(answer).trim())) {
            errors[`${path}.response.answer`] = t`Please enter a valid phone number (digits, spaces, +, -, ( ) only, minimum 8 characters)`;
        }
    };

    const validateStep = (current: Step): Record<string, string> => {
        const errors: Record<string, string> = {};
        const {order, products} = form.values;

        if (current.kind === 'name') {
            if (!order.first_name.trim()) errors['order.first_name'] = requiredMessage;
            if (!order.last_name.trim()) errors['order.last_name'] = requiredMessage;
            if (order.first_name.length > 40) errors['order.first_name'] = t`First name must be under 40 characters`;
            if (order.last_name.length > 40) errors['order.last_name'] = t`Last name must be under 40 characters`;
        }

        if (current.kind === 'email') {
            if (!EMAIL_PATTERN.test(order.email.trim())) {
                errors['order.email'] = t`A valid email is required`;
            }
            if (!order.email_confirmation.trim()) {
                errors['order.email_confirmation'] = t`Please confirm your email address`;
            } else if (order.email_confirmation.trim() !== order.email.trim()) {
                errors['order.email_confirmation'] = t`Email addresses do not match`;
            }
        }

        [...current.questionIndexes, ...current.consentIndexes].forEach(index =>
            validateQuestion(orderQuestions[index], `order.questions.${index}`, errors)
        );

        if (current.kind === 'participants') {
            products.forEach((participant, index) => {
                if (index > 0) {
                    if (!participant.first_name.trim()) errors[`products.${index}.first_name`] = requiredMessage;
                    if (!participant.last_name.trim()) errors[`products.${index}.last_name`] = requiredMessage;
                }
                if (isAgeCheckEnabled && !participant.birth_date) {
                    errors[`products.${index}.birth_date`] = t`Date of birth is required`;
                }
                participantQuestions.forEach((question, questionIndex) =>
                    validateQuestion(question, `products.${index}.questions.${questionIndex}`, errors)
                );
            });
        }

        return errors;
    };

    const completeMutation = useCompleteEmbeddedRegistration();

    const stepIndexForErrorKey = (key: string, phase: 'create' | 'complete'): number => {
        const indexOf = (kind: StepKind) => steps.findIndex(candidate => candidate.kind === kind);
        if (key.startsWith('order.first_name') || key.startsWith('order.last_name')) return indexOf('name');
        if (key.startsWith('order.email')) return indexOf('email');
        const questionMatch = key.match(/^order\.questions\.(\d+)/);
        if (questionMatch) {
            const questionIndex = Number(questionMatch[1]);
            const found = steps.findIndex(candidate =>
                candidate.questionIndexes.includes(questionIndex) || candidate.consentIndexes.includes(questionIndex)
            );
            return found >= 0 ? found : indexOf('confirm');
        }
        if (key.startsWith('products') || key.startsWith('promo_code') || phase === 'create') return indexOf('participants');
        return indexOf('confirm');
    };

    const handleServerError = ({phase, cause}: EmbeddedRegistrationError) => {
        const status = cause?.response?.status;
        const data = cause?.response?.data;
        const rawErrors: Record<string, string | string[]> = data?.errors ?? {};
        const fieldErrors: Record<string, string> = {};
        let targetIndex = -1;
        let message: string | null = null;

        Object.entries(rawErrors).forEach(([key, value]) => {
            const text = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '');
            if (!text) {
                return;
            }
            fieldErrors[key] = text;
            const index = stepIndexForErrorKey(key, phase);
            if (targetIndex === -1 || index < targetIndex) {
                targetIndex = index;
                message = text;
            }
        });

        if (message === null) {
            message = data?.message || t`Something went wrong. Please try again.`;
            targetIndex = phase === 'create'
                ? steps.findIndex(candidate => candidate.kind === 'participants')
                : steps.length - 1;
        }

        if (phase === 'complete' && (status === 404 || status === 409)) {
            setPendingOrder(null);
        }

        setCreationFailed(phase === 'create');
        form.setErrors(fieldErrors);
        setStepErrors({[steps[targetIndex].id]: message as string});
        goToStep(targetIndex, 'error');
    };

    const submitRegistration = () => {
        if (!ticket) {
            return;
        }
        const {order, products} = form.values;
        const quantity = products.length;
        const toAnswers = (answers: QuestionAnswerValue[]) => answers.map(answer => ({
            question_id: answer.question_id,
            response: isAnswerEmpty(answer.response.answer) ? {} : {answer: answer.response.answer},
        }));

        const createPayload: ProductFormPayload = {
            products: [{
                product_id: Number(ticket.product.id),
                quantities: [{price_id: Number(ticket.price.id), quantity}],
            }],
            promo_code: promoCode,
            affiliate_code: readAffiliateCode(eventId),
            qr_link_code: getQrLinkCode(),
            tracking_context: getOrderTrackingContext(),
            session_identifier: getSessionIdentifier(),
        };

        const email = order.email.trim();
        const completePayload = {
            order: {
                first_name: order.first_name.trim(),
                last_name: order.last_name.trim(),
                email,
                email_confirmation: email,
                address: {},
                questions: toAnswers(order.questions),
                opted_into_marketing: order.opted_into_marketing,
            },
            products: products.map((participant, index) => ({
                product_id: Number(ticket.product.id),
                product_price_id: Number(ticket.price.id),
                first_name: index === 0 ? order.first_name.trim() : participant.first_name.trim(),
                last_name: index === 0 ? order.last_name.trim() : participant.last_name.trim(),
                email,
                email_confirmation: email,
                birth_date: participant.birth_date,
                questions: toAnswers(participant.questions),
            })),
        } as unknown as FinaliseOrderPayload;

        setStepErrors({});
        setCreationFailed(false);
        completeMutation.mutate({
            eventId: eventId as number,
            signature: `${ticket.product.id}:${ticket.price.id}:${quantity}:${promoCode ?? ''}`,
            pendingOrder,
            createPayload,
            completePayload,
            onOrderCreated: setPendingOrder,
        }, {
            onSuccess: (completedOrder) => {
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_FREE);
                const nextPage = completedOrder.is_payment_required ? 'payment' : 'summary';
                navigate(eventCheckoutPath(eventId as number, completedOrder.short_id, nextPage));
            },
            onError: handleServerError,
        });
    };

    const handleNext = () => {
        if (completeMutation.isPending) {
            return;
        }
        hasInteracted.current = true;
        const errors = validateStep(step);
        if (Object.keys(errors).length > 0) {
            form.setErrors(errors);
            pendingFocus.current = 'error';
            setStepErrors(previous => ({...previous, [step.id]: ''}));
            return;
        }
        form.clearErrors();
        setStepErrors(previous => ({...previous, [step.id]: ''}));
        if (isLastStep) {
            submitRegistration();
            return;
        }
        goToStep(safeStepIndex + 1);
    };

    const handleSubmit = (submitEvent: FormEvent) => {
        submitEvent.preventDefault();
        handleNext();
    };

    const handleKeyDown = (keyEvent: KeyboardEvent<HTMLFormElement>) => {
        if (keyEvent.key !== 'Enter' || keyEvent.shiftKey || keyEvent.nativeEvent.isComposing) {
            return;
        }
        const target = keyEvent.target as HTMLElement;
        if (target.tagName === 'TEXTAREA' || target.tagName === 'A' || target.getAttribute('aria-expanded') === 'true') {
            return;
        }
        if (target.tagName === 'BUTTON' && target.getAttribute('type') !== 'submit' && !target.getAttribute('role')) {
            return;
        }
        keyEvent.preventDefault();
        if (target.getAttribute('role') === 'radio' && target.getAttribute('aria-checked') !== 'true') {
            target.click();
            window.requestAnimationFrame(() => nextRef.current());
            return;
        }
        handleNext();
    };
    nextRef.current = handleNext;

    const addParticipant = () => {
        if (!ticket || form.values.products.length >= ticket.maxParticipants) {
            return;
        }
        const participant = makeParticipant();
        form.insertListItem('products', participant);
        pendingFocus.current = participant.key;
    };

    const removeParticipant = (index: number) => {
        form.removeListItem('products', index);
        pendingFocus.current = 'add-participant';
    };

    const promoMutation = useMutation({
        mutationFn: async (code: string | null) => {
            if (code) {
                const validation = await promoCodeClientPublic.validateCode(eventId, code);
                if (!validation.valid) {
                    return {valid: false as const};
                }
            }
            const {data} = await eventsClientPublic.findByID(eventId, code);
            return {valid: true as const, event: data};
        },
    });

    const applyCode = () => {
        const code = codeValue.trim();
        if (code.length < 3) {
            setCodeError(t`This invitation code is not valid.`);
            return;
        }
        setCodeError(null);
        promoMutation.mutate(code, {
            onSuccess: (result) => {
                if (!result.valid) {
                    setCodeError(t`This invitation code is not valid.`);
                    return;
                }
                const unlocked = resolveEmbeddedRegistrationTicket(result.event, publicProductIds ?? productIdsOf(event));
                if (!unlocked?.unlockedByCode) {
                    setCodeError(t`This code does not unlock any invitation for this event.`);
                    return;
                }
                setEvent(result.event);
                setPromoCode(code);
                setCodeOpen(false);
                setCodeValue('');
                setPendingOrder(null);
                addQueryStringToUrl('promo_code', code);
            },
            onError: () => setCodeError(t`This invitation code is not valid.`),
        });
    };

    const removeCode = () => {
        promoMutation.mutate(null, {
            onSuccess: (result) => {
                if (result.valid) {
                    setEvent(result.event);
                }
                setPromoCode(null);
                setPendingOrder(null);
                removeQueryStringFromUrl('promo_code');
            },
        });
    };

    const relevantQuestions = [...orderQuestions, ...participantQuestions];
    const cannotEmbed = questionsQuery.isError
        || (questionsQuery.isFetched && hasUnsupportedQuestions(relevantQuestions))
        || !!event.settings?.require_billing_address
        || (publicProductIds !== null && !ticket);

    if (forceClassic || cannotEmbed) {
        return <>{renderFallback()}</>;
    }

    const isLoading = !questionsQuery.isFetched || publicProductIds === null || !ticket;
    const totalSteps = steps.length;
    const currentStepNumber = safeStepIndex + 1;
    const progress = totalSteps > 1 ? safeStepIndex / (totalSteps - 1) : 0;
    const titleId = `embedded-registration-${step.id}-title`;
    const participantsCount = form.values.products.length;
    const maxParticipants = ticket?.maxParticipants ?? 1;

    const renderQuestion = (question: Question, path: string, large: boolean, labelId?: string) => {
        const inputProps = form.getInputProps(`${path}.response.answer`);
        const common = {
            label: question.title,
            withAsterisk: question.required,
            description: question.description
                ? <UserGeneratedContent dangerouslySetInnerHTML={{__html: question.description}}/>
                : undefined,
            classNames: large ? largeFieldClassNames : fieldClassNames,
            error: inputProps.error,
            labelProps: labelId ? {id: labelId} : undefined,
        };

        switch (question.type) {
            case QuestionType.PHONE:
                return <TextInput {...inputProps} {...common} type="tel" inputMode="tel" autoComplete="tel"/>;
            case QuestionType.DATE:
                return <TextInput {...inputProps} {...common} type="date"/>;
            case QuestionType.MULTI_LINE_TEXT:
                return <Textarea {...inputProps} {...common} autosize minRows={2}/>;
            case QuestionType.DROPDOWN:
                if ((question.options?.length ?? 0) > 6) {
                    return (
                        <Select
                            {...inputProps}
                            {...common}
                           
                            data={question.options ?? []}
                            searchable
                            placeholder={t`Choose an option`}
                            nothingFoundMessage={t`No results`}
                            classNames={{
                                ...common.classNames,
                                dropdown: classes.selectDropdown,
                                option: classes.selectOption,
                            }}
                        />
                    );
                }
                return renderChoiceGroup(question, inputProps, common);
            case QuestionType.RADIO:
                return renderChoiceGroup(question, inputProps, common);
            case QuestionType.CHECKBOX:
                return (
                    <Checkbox.Group {...inputProps} {...common}>
                        <div className={classes.choices}>
                            {question.options?.map(option => (
                                <Checkbox.Card key={option} value={option} className={classes.choiceCard} radius="md">
                                    <span className={classes.choiceInner}>
                                        <Checkbox.Indicator className={classes.choiceIndicator} color="var(--event-primary-color)" iconColor="var(--event-accent-contrast)"/>
                                        <span className={classes.choiceText}>{option}</span>
                                    </span>
                                </Checkbox.Card>
                            ))}
                        </div>
                    </Checkbox.Group>
                );
            default:
                return <TextInput {...inputProps} {...common}/>;
        }
    };

    const renderChoiceGroup = (question: Question, inputProps: ReturnType<typeof form.getInputProps>, common: Record<string, any>) => (
        <Radio.Group {...inputProps} {...common}>
            <div className={classes.choices}>
                {question.options?.map(option => (
                    <Radio.Card key={option} value={option} className={classes.choiceCard} radius="md">
                        <span className={classes.choiceInner}>
                            <Radio.Indicator className={classes.choiceIndicator} color="var(--event-primary-color)" iconColor="var(--event-accent-contrast)"/>
                            <span className={classes.choiceText}>{option}</span>
                        </span>
                    </Radio.Card>
                ))}
            </div>
        </Radio.Group>
    );

    const renderStepContent = (step: Step, titleId: string) => {
        switch (step.kind) {
            case 'name':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`What is your name?`}</h2>
                        <p className={classes.stepHelp}>{t`As shown on your identity document.`}</p>
                        <div className={classes.fieldRow}>
                            <TextInput
                                {...form.getInputProps('order.first_name')}
                                label={t`First Name`}
                                withAsterisk
                                autoComplete="given-name"
                                classNames={fieldClassNames}
                            />
                            <TextInput
                                {...form.getInputProps('order.last_name')}
                                label={t`Last Name`}
                                withAsterisk
                                autoComplete="family-name"
                                classNames={fieldClassNames}
                            />
                        </div>
                    </>
                );
            case 'email':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`Where should we send your tickets?`}</h2>
                        <p className={classes.stepHelp}>{t`Your tickets will be sent to this address.`}</p>
                        <div className={classes.fieldStack}>
                            <TextInput
                                {...form.getInputProps('order.email')}
                                type="email"
                                label={t`Email Address`}
                                withAsterisk
                                autoComplete="email"
                                inputMode="email"
                                classNames={fieldClassNames}
                            />
                            <TextInput
                                {...form.getInputProps('order.email_confirmation')}
                                type="email"
                                label={t`Confirm Email Address`}
                                withAsterisk
                                autoComplete="email"
                                inputMode="email"
                                classNames={fieldClassNames}
                            />
                        </div>
                    </>
                );
            case 'phone':
            case 'questions': {
                const index = step.questionIndexes[0];
                return renderQuestion(orderQuestions[index], `order.questions.${index}`, true, titleId);
            }
            case 'participants':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`Who is coming?`}</h2>
                        <p className={classes.stepHelp}>
                            {maxParticipants > 1
                                ? t`You can register up to ${maxParticipants} people, including yourself.`
                                : t`This registration is for one person.`}
                        </p>
                        <ol className={classes.participants}>
                            {form.values.products.map((participant, index) => {
                                const participantNumber = index + 1;
                                const buyerName = `${form.values.order.first_name} ${form.values.order.last_name}`.trim();
                                return (
                                    <li key={participant.key} className={classes.participant}>
                                        <div className={classes.participantHeader}>
                                            <span className={classes.participantNumber} aria-hidden="true">{participantNumber}</span>
                                            <h3 className={classes.participantTitle}>
                                                {index === 0
                                                    ? <>{buyerName} <span className={classes.participantTag}>{t`you`}</span></>
                                                    : t`Participant ${participantNumber}`}
                                            </h3>
                                            {index > 0 && index >= (ticket?.minParticipants ?? 1) && (
                                                <ActionIcon
                                                    variant="subtle"
                                                    className={classes.removeButton}
                                                    onClick={() => removeParticipant(index)}
                                                    aria-label={t`Remove participant ${participantNumber}`}
                                                    title={t`Remove`}
                                                >
                                                    <IconX size={18}/>
                                                </ActionIcon>
                                            )}
                                        </div>
                                        {index > 0 && (
                                            <div className={classes.fieldRow}>
                                                <TextInput
                                                    {...form.getInputProps(`products.${index}.first_name`)}
                                                    data-focus-key={participant.key}
                                                    label={t`First Name`}
                                                    withAsterisk
                                                    classNames={fieldClassNames}
                                                />
                                                <TextInput
                                                    {...form.getInputProps(`products.${index}.last_name`)}
                                                    label={t`Last Name`}
                                                    withAsterisk
                                                    classNames={fieldClassNames}
                                                />
                                            </div>
                                        )}
                                        {isAgeCheckEnabled && (
                                            <TextInput
                                                {...form.getInputProps(`products.${index}.birth_date`)}
                                                type="date"
                                                label={t`Date of birth`}
                                                description={ageRestrictionHint}
                                                withAsterisk
                                                max={new Date().toISOString().slice(0, 10)}
                                                classNames={fieldClassNames}
                                            />
                                        )}
                                        {participantQuestions.map((question, questionIndex) => (
                                            <div key={question.id} className={classes.participantQuestion}>
                                                {renderQuestion(question, `products.${index}.questions.${questionIndex}`, false)}
                                            </div>
                                        ))}
                                    </li>
                                );
                            })}
                        </ol>
                        {maxParticipants > 1 && (
                            <div className={classes.addRow}>
                                <button
                                    type="button"
                                    className={classes.addButton}
                                    onClick={addParticipant}
                                    disabled={participantsCount >= maxParticipants}
                                    data-focus-key="add-participant"
                                >
                                    <IconPlus size={18} aria-hidden="true"/>
                                    {t`Add a participant`}
                                </button>
                                <span className={classes.addHint}>
                                    {t`${participantsCount} of ${maxParticipants} places`}
                                </span>
                            </div>
                        )}
                    </>
                );
            case 'confirm':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`Ready to confirm?`}</h2>
                        <dl className={classes.summary}>
                            <div>
                                <dt>{t`Registration`}</dt>
                                <dd>{ticket?.product.title}</dd>
                            </div>
                            <div>
                                <dt>{t`Contact`}</dt>
                                <dd>{form.values.order.first_name} {form.values.order.last_name}<br/>{form.values.order.email}</dd>
                            </div>
                            <div>
                                <dt>{t`Participants`}</dt>
                                <dd>
                                    {form.values.products.map((participant, index) => (
                                        <span key={participant.key} className={classes.summaryLine}>
                                            {index === 0
                                                ? `${form.values.order.first_name} ${form.values.order.last_name}`
                                                : `${participant.first_name} ${participant.last_name}`}
                                        </span>
                                    ))}
                                </dd>
                            </div>
                        </dl>
                        {!!event.settings?.pre_checkout_message && (
                            <UserGeneratedContent
                                className={classes.preCheckout}
                                dangerouslySetInnerHTML={{__html: event.settings.pre_checkout_message}}
                            />
                        )}
                        {!!getConfig('VITE_TOS_URL') && (
                            <p className={classes.tosNotice}>
                                <Trans>
                                    By continuing, you agree to the{' '}
                                    <a
                                        href={getTermsOfSaleUrl() as string}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {getAppName()} Terms of Service
                                    </a>
                                </Trans>
                            </p>
                        )}
                    </>
                );
        }
    };

    const renderConsents = (current: Step) => current.consentIndexes.length > 0 && (
        <div className={classes.consents}>
            {current.consentIndexes.map(index => {
                const question = orderQuestions[index];
                const option = question.options?.[0] ?? question.title;
                const path = `order.questions.${index}.response.answer`;
                const inputProps = form.getInputProps(path);
                const checked = Array.isArray(inputProps.value) && inputProps.value.includes(option);
                return (
                    <Checkbox
                        key={question.id}
                        checked={checked}
                        onChange={(changeEvent) => form.setFieldValue(path, changeEvent.currentTarget.checked ? [option] : [])}
                        error={inputProps.error}
                        required={question.required}
                        color="var(--event-primary-color)"
                        iconColor="var(--event-accent-contrast)"
                        classNames={{
                            root: classes.consent,
                            body: classes.consentBody,
                            label: classes.consentLabel,
                            input: classes.consentInput,
                            error: classes.fieldError,
                        }}
                        label={(
                            <>
                                {option}
                                {question.required && <span className={classes.fieldRequired} aria-hidden="true"> *</span>}
                            </>
                        )}
                    />
                );
            })}
        </div>
    );

    const renderStepBody = (current: Step, active: boolean) => {
        const index = steps.findIndex(candidate => candidate.id === current.id);
        const isLast = index === steps.length - 1;
        const currentError = active ? stepErrors[current.id] : '';
        const currentTitleId = active ? titleId : `${titleId}-leaving`;

        return (
            <>
                {renderStepContent(current, currentTitleId)}
                {renderConsents(current)}

                {!!currentError && (
                    <div className={classes.stepError} role="alert">
                        <span>{currentError}</span>
                        {creationFailed && current.kind === 'participants' && (
                            <button type="button" className={classes.linkButton} onClick={() => setForceClassic(true)}>
                                {t`See the other ticket options`}
                            </button>
                        )}
                    </div>
                )}

                <div className={classes.nav}>
                    {index > 0 && (
                        <button
                            type="button"
                            className={classes.backButton}
                            onClick={() => goToStep(index - 1)}
                            disabled={completeMutation.isPending}
                        >
                            <IconArrowLeft size={18} aria-hidden="true"/>
                            {t`Previous`}
                        </button>
                    )}
                    <Button
                        type={active ? 'submit' : 'button'}
                        className={classes.nextButton}
                        loading={active && completeMutation.isPending}
                        rightSection={isLast ? <IconCheck size={20}/> : <IconArrowRight size={20}/>}
                    >
                        {isLast ? t`Confirm my registration` : t`Next`}
                    </Button>
                    {!isLast && (
                        <span className={classes.enterHint} aria-hidden="true">
                            {t`or press Enter`} <IconCornerDownLeft size={14}/>
                        </span>
                    )}
                </div>
            </>
        );
    };

    return (
        <section className={classes.root} ref={sectionRef} id="tickets" aria-label={t`Registration`}>
            <div className={classes.panel} ref={panelRef}>
                {isLoading ? (
                    <p className={classes.loading} role="status">{t`Loading...`}</p>
                ) : (
                    <>
                        <div className={classes.header}>
                            <span className={classes.eyebrow}>
                                <IconTicket size={16} aria-hidden="true"/>
                                {t`Free registration`}
                            </span>
                        </div>
                        <div
                            className={classes.progress}
                            role="progressbar"
                            aria-label={t`Registration progress`}
                            aria-valuemin={1}
                            aria-valuemax={totalSteps}
                            aria-valuenow={currentStepNumber}
                            aria-valuetext={t`Step ${currentStepNumber} of ${totalSteps}`}
                        >
                            <span className={classes.progressBar} style={{transform: `scaleX(${Math.max(progress, 0.04)})`}}/>
                        </div>
                        <p className={classes.srOnly} aria-live="polite">
                            {hasInteracted.current ? t`Step ${currentStepNumber} of ${totalSteps}` : ''}
                        </p>

                        <form noValidate onSubmit={handleSubmit} onKeyDown={handleKeyDown} className={classes.form}>
                            <div className={classes.stage}>
                                {leaving && (
                                    <div
                                        key={`leaving-${leaving.step.id}`}
                                        className={classes.step}
                                        data-phase="leave"
                                        data-direction={leaving.direction}
                                        aria-hidden="true"
                                        {...{inert: ''}}
                                    >
                                        {renderStepBody(leaving.step, false)}
                                    </div>
                                )}
                                <div
                                    key={step.id}
                                    ref={stepRef}
                                    className={classes.step}
                                    data-phase={leaving ? 'enter' : 'idle'}
                                    data-direction={direction}
                                    role="group"
                                    aria-labelledby={titleId}
                                    tabIndex={-1}
                                >
                                    {renderStepBody(step, true)}
                                </div>
                            </div>
                        </form>

                        <div className={classes.codeRow}>
                            {promoCode ? (
                                <div className={classes.codeApplied}>
                                    <span>{t`Invitation code ${promoCode} applied: ${ticket?.product.title}`}</span>
                                    <button type="button" className={classes.linkButton} onClick={removeCode} disabled={promoMutation.isPending}>
                                        {t`Remove`}
                                    </button>
                                </div>
                            ) : codeOpen ? (
                                <div className={classes.codeForm}>
                                    <TextInput
                                        value={codeValue}
                                        onChange={(changeEvent) => setCodeValue(changeEvent.currentTarget.value)}
                                        onKeyDown={(keyEvent) => {
                                            if (keyEvent.key === 'Enter') {
                                                keyEvent.preventDefault();
                                                applyCode();
                                            }
                                        }}
                                        label={t`Invitation code`}
                                        error={codeError}
                                        autoComplete="off"
                                        autoCapitalize="characters"
                                        classNames={{...fieldClassNames, root: classes.codeField}}
                                        ref={codeInputRef}
                                    />
                                    <button type="button" className={classes.codeApply} onClick={applyCode} disabled={promoMutation.isPending}>
                                        {t`Apply`}
                                    </button>
                                    <button
                                        type="button"
                                        className={classes.linkButton}
                                        onClick={() => {
                                            setCodeOpen(false);
                                            setCodeError(null);
                                        }}
                                    >
                                        {t`Cancel`}
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    className={classes.linkButton}
                                    onClick={() => {
                                        hasInteracted.current = true;
                                        setCodeOpen(true);
                                    }}
                                >
                                    {t`I have an invitation code`}
                                </button>
                            )}
                        </div>
                    </>
                )}
            </div>
        </section>
    );
};

export default EmbeddedRegistration;
