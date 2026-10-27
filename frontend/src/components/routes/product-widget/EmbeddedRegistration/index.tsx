import {FormEvent, KeyboardEvent, ReactNode, RefObject, useEffect, useMemo, useRef, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {useNavigate} from "react-router";
import {useMutation, useQuery} from "@tanstack/react-query";
import {useForm} from "@mantine/form";
import {ActionIcon, Button, Checkbox, Radio, Select, Textarea, TextInput} from "@mantine/core";
import {
    IconArrowLeft,
    IconArrowRight,
    IconCheck,
    IconCornerDownLeft,
    IconLock,
    IconMinus,
    IconPlus,
    IconTicket,
    IconX
} from "@tabler/icons-react";
import classNames from "classnames";
import {Event, Product, ProductPrice, Question, QuestionType} from "../../../../types.ts";
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
    computeCheckoutTotals,
    EMAIL_PATTERN,
    EmbeddedCheckoutItem,
    getEmbeddedCheckoutLines,
    getEventProducts,
    getProductMaxPerOrder,
    getRequiredCheckoutItems,
    hasUnsupportedCheckoutProducts,
    hasUnsupportedQuestions,
    isAnswerEmpty,
    isFreeEvent,
    isSingleConsentQuestion,
    PHONE_PATTERN,
    resolveEmbeddedRegistrationTicket,
    sortQuestions,
} from "../../../../utilites/embeddedRegistration.ts";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {getContactCode} from "../../../../utilites/contactLinkAttribution.ts";
import {getSessionIdentifier} from "../../../../utilites/sessionIdentifier.ts";
import {getOrderTrackingContext, getQrLinkCode} from "../../../../utilites/qrAttribution.ts";
import {addQueryStringToUrl, removeQueryStringFromUrl} from "../../../../utilites/helpers.ts";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {AnalyticsEvents, trackEvent} from "../../../../utilites/analytics.ts";
import {getConfig} from "../../../../utilites/config.ts";
import {getAppName, getTermsOfSaleUrl} from "../../../../utilites/branding.ts";
import {UserGeneratedContent} from "../../../common/UserGeneratedContent";
import {SaleCountdown} from "./SaleCountdown.tsx";
import {LineDescription} from "./LineDescription.tsx";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import classes from "./EmbeddedRegistration.module.scss";

dayjs.extend(utc);

type StepKind = 'selection' | 'name' | 'email' | 'phone' | 'questions' | 'participants' | 'confirm' | 'summary';

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
    line_key?: string;
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
    onAlreadyRegistered?: () => void;
    variant?: 'immersive' | 'card';
    className?: string;
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

const initialSelection = (event: Event): Record<string, number> => {
    const ticketLines = getEmbeddedCheckoutLines(event).filter(line => line.isTicket);
    if (ticketLines.length !== 1) {
        return {};
    }
    const [line] = ticketLines;
    return {[line.key]: Math.min(line.max, Math.max(1, Number(line.product.min_per_order) || 1))};
};

const productIdOfLineKey = (key?: string) => Number(String(key ?? '').split(':')[0]);

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

export const EmbeddedRegistration = ({
    event: initialEvent,
    promoCode: initialPromoCode,
    promoCodeValid,
    sectionRef,
    renderFallback,
    onAlreadyRegistered,
    variant = 'immersive',
    className,
}: EmbeddedRegistrationProps) => {
    const navigate = useNavigate();
    const eventId = initialEvent.id;
    const startsWithCode = !!promoCodeValid && !!initialPromoCode;
    const [isPaidMode] = useState(() => !isFreeEvent(initialEvent));

    const [event, setEvent] = useState<Event>(initialEvent);
    const [promoCode, setPromoCode] = useState<string | null>(startsWithCode ? initialPromoCode! : null);
    const [publicProductIds, setPublicProductIds] = useState<Set<number> | null>(startsWithCode && !isPaidMode ? null : productIdsOf(initialEvent));
    const [selection, setSelection] = useState<Record<string, number>>(() => initialSelection(initialEvent));
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
    const [confirmingRemovalKey, setConfirmingRemovalKey] = useState<string | null>(null);

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

    const ticket = isPaidMode ? null : resolveEmbeddedRegistrationTicket(event, publicProductIds);
    const productId = ticket?.product.id;
    const isPerTicket = event.settings?.attendee_details_collection_method !== 'PER_ORDER';
    const currency = event.currency || 'EUR';

    const checkoutLines = useMemo(() => isPaidMode ? getEmbeddedCheckoutLines(event) : [], [event, isPaidMode]);
    const ticketLines = checkoutLines.filter(line => line.isTicket);
    const selectedLineItems: EmbeddedCheckoutItem[] = checkoutLines
        .filter(line => (selection[line.key] ?? 0) > 0)
        .map(line => ({
            key: line.key,
            product: line.product,
            price: line.price,
            quantity: Math.min(selection[line.key], line.max),
            isTicket: line.isTicket,
            isRequired: false,
        }));
    const selectedTicketItems = selectedLineItems.filter(item => item.isTicket);
    const selectedTicketCount = selectedTicketItems.reduce((sum, item) => sum + item.quantity, 0);
    const requiredItems = getRequiredCheckoutItems(event, isPaidMode ? selectedTicketCount : form.values.products.length);
    const checkoutItems = [...selectedLineItems, ...(selectedTicketCount > 0 ? requiredItems : [])];
    const totals = computeCheckoutTotals(checkoutItems);
    const totalWithoutFees = Math.round((totals.total - totals.fees) * 100) / 100;
    const hasServiceFees = checkoutLines.some(line => Number(line.price.fee_total) > 0);
    const ticketSignature = selectedTicketItems.map(item => `${item.key}x${item.quantity}`).join(',');
    const formatAmount = (amount: number) => formatCurrency(amount, currency);

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
    const productQuestions = useMemo(
        () => sortQuestions((questionsQuery.data ?? []).filter(question => question.belongs_to === 'PRODUCT')),
        [questionsQuery.data]
    );
    const questionsForProduct = (id: unknown) =>
        productQuestions.filter(question => question.product_ids?.includes(Number(id)));
    const questionsForParticipant = (participant: ParticipantValue) => isPaidMode
        ? questionsForProduct(productIdOfLineKey(participant.line_key))
        : participantQuestions;

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

    useEffect(() => {
        const existing = form.values.order.questions;
        form.setFieldValue('order.questions', orderQuestions.map(question =>
            existing.find(answer => answer.question_id === question.id) ?? emptyAnswer(question)
        ));
    }, [orderQuestions]);

    useEffect(() => {
        if (!isPaidMode) {
            return;
        }
        const pool = new Map<string, ParticipantValue[]>();
        form.values.products.forEach(participant => {
            if (participant.line_key) {
                pool.set(participant.line_key, [...(pool.get(participant.line_key) ?? []), participant]);
            }
        });
        form.setFieldValue('products', selectedTicketItems.flatMap(item =>
            Array.from({length: item.quantity}, () => {
                const base: ParticipantValue = pool.get(item.key)?.shift() ?? {
                    key: nextParticipantKey(),
                    line_key: item.key,
                    first_name: '',
                    last_name: '',
                    birth_date: '',
                    questions: [],
                };
                return {
                    ...base,
                    questions: questionsForProduct(item.product.id).map(question =>
                        base.questions.find(answer => answer.question_id === question.id) ?? emptyAnswer(question)
                    ),
                };
            })
        ));
    }, [isPaidMode, ticketSignature, productQuestions]);

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

    const needsParticipantsStep = !isPaidMode
        || isPerTicket
        || isAgeCheckEnabled
        || selectedTicketItems.some(item => questionsForProduct(item.product.id).length > 0);

    const steps = useMemo<Step[]>(() => {
        const result: Step[] = [
            ...(isPaidMode ? [{id: 'selection', kind: 'selection' as StepKind, questionIndexes: [], consentIndexes: []}] : []),
            {id: 'name', kind: 'name', questionIndexes: [], consentIndexes: []},
            {id: 'email', kind: 'email', questionIndexes: [], consentIndexes: []},
        ];
        let anchor = result[result.length - 1];
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
        if (needsParticipantsStep) {
            result.push({id: 'participants', kind: 'participants', questionIndexes: [], consentIndexes: []});
        }
        result.push(isPaidMode
            ? {id: 'summary', kind: 'summary', questionIndexes: [], consentIndexes: []}
            : {id: 'confirm', kind: 'confirm', questionIndexes: [], consentIndexes: []});
        return result;
    }, [orderQuestions, isPaidMode, needsParticipantsStep]);

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

        if (current.kind === 'selection') {
            if (selectedTicketCount === 0) {
                errors._step = t`Choose at least one ticket.`;
            }
            const quantitiesByProduct = new Map<number, number>();
            selectedLineItems.forEach(item => quantitiesByProduct.set(
                Number(item.product.id),
                (quantitiesByProduct.get(Number(item.product.id)) ?? 0) + item.quantity
            ));
            quantitiesByProduct.forEach((quantity, id) => {
                const product = checkoutLines.find(line => Number(line.product.id) === id)?.product;
                if (!product || errors._step) {
                    return;
                }
                const title = product.title;
                const max = getProductMaxPerOrder(product);
                const min = Number(product.min_per_order) || 1;
                if (quantity > max) {
                    errors._step = t`You can choose up to ${max} for ${title}.`;
                } else if (quantity < min) {
                    errors._step = t`Choose at least ${min} for ${title}.`;
                }
            });
        }

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
        }

        [...current.questionIndexes, ...current.consentIndexes].forEach(index =>
            validateQuestion(orderQuestions[index], `order.questions.${index}`, errors)
        );

        if (current.kind === 'participants') {
            products.forEach((participant, index) => {
                if (isPaidMode ? isPerTicket : index > 0) {
                    if (!participant.first_name.trim()) errors[`products.${index}.first_name`] = requiredMessage;
                    if (!participant.last_name.trim()) errors[`products.${index}.last_name`] = requiredMessage;
                }
                if (isAgeCheckEnabled && !participant.birth_date) {
                    errors[`products.${index}.birth_date`] = t`Date of birth is required`;
                }
                questionsForParticipant(participant).forEach((question, questionIndex) =>
                    validateQuestion(question, `products.${index}.questions.${questionIndex}`, errors)
                );
            });
        }

        return errors;
    };

    const completeMutation = useCompleteEmbeddedRegistration();

    const lastStepIndex = steps.length - 1;

    const stepIndexForErrorKey = (key: string, phase: 'create' | 'complete'): number => {
        const indexOf = (kind: StepKind) => {
            const found = steps.findIndex(candidate => candidate.kind === kind);
            return found >= 0 ? found : lastStepIndex;
        };
        if (isPaidMode && (phase === 'create' || key.startsWith('promo_code'))) {
            return key.startsWith('promo_code') ? lastStepIndex : indexOf('selection');
        }
        if (key.startsWith('order.first_name') || key.startsWith('order.last_name')) return indexOf('name');
        if (key.startsWith('order.email')) return indexOf('email');
        const questionMatch = key.match(/^order\.questions\.(\d+)/);
        if (questionMatch) {
            const questionIndex = Number(questionMatch[1]);
            const found = steps.findIndex(candidate =>
                candidate.questionIndexes.includes(questionIndex) || candidate.consentIndexes.includes(questionIndex)
            );
            return found >= 0 ? found : lastStepIndex;
        }
        if (key.startsWith('products') || key.startsWith('promo_code') || phase === 'create') return indexOf('participants');
        return lastStepIndex;
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
                ? stepIndexForErrorKey('', 'create')
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

    const toAnswers = (answers: QuestionAnswerValue[]) => answers.map(answer => ({
        question_id: answer.question_id,
        response: isAnswerEmpty(answer.response.answer) ? {} : {answer: answer.response.answer},
    }));

    const groupByProduct = (items: EmbeddedCheckoutItem[]) => {
        const grouped = new Map<number, { price_id: number, quantity: number }[]>();
        items.forEach(item => grouped.set(Number(item.product.id), [
            ...(grouped.get(Number(item.product.id)) ?? []),
            {price_id: Number(item.price.id), quantity: item.quantity},
        ]));
        return [...grouped.entries()].map(([product_id, quantities]) => ({product_id, quantities}));
    };

    const extraItemEntries = (items: EmbeddedCheckoutItem[], firstName: string, lastName: string, email: string) =>
        items.flatMap(item => Array.from({length: item.quantity}, () => ({
            product_id: Number(item.product.id),
            product_price_id: Number(item.price.id),
            first_name: item.isTicket ? firstName : '',
            last_name: item.isTicket ? lastName : '',
            email: item.isTicket ? email : '',
            email_confirmation: item.isTicket ? email : '',
            birth_date: '',
            questions: [],
        })));

    const trackingFields = () => ({
        promo_code: promoCode,
        affiliate_code: readAffiliateCode(eventId),
        qr_link_code: getQrLinkCode(),
        contact_code: getContactCode(),
        tracking_context: getOrderTrackingContext(),
        session_identifier: getSessionIdentifier(),
    });

    const navigateAfterCompletion = (completedOrder: { is_payment_required?: boolean, short_id: string }) => {
        if (!completedOrder.is_payment_required) {
            trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_FREE);
        }
        const nextPage = completedOrder.is_payment_required ? 'payment' : 'summary';
        navigate(eventCheckoutPath(eventId as number, completedOrder.short_id, nextPage));
    };

    const submitPaidOrder = () => {
        const {order, products} = form.values;
        const firstName = order.first_name.trim();
        const lastName = order.last_name.trim();
        const email = order.email.trim();

        const createPayload = {
            products: groupByProduct(checkoutItems),
            ...trackingFields(),
        } as ProductFormPayload;

        const completePayload = {
            order: {
                first_name: firstName,
                last_name: lastName,
                email,
                email_confirmation: email,
                address: {},
                questions: toAnswers(order.questions),
                opted_into_marketing: order.opted_into_marketing,
            },
            products: [
                ...products.map(participant => {
                    const [product_id, product_price_id] = String(participant.line_key).split(':').map(Number);
                    return {
                        product_id,
                        product_price_id,
                        first_name: isPerTicket ? participant.first_name.trim() : firstName,
                        last_name: isPerTicket ? participant.last_name.trim() : lastName,
                        email,
                        email_confirmation: email,
                        birth_date: participant.birth_date,
                        questions: toAnswers(participant.questions),
                    };
                }),
                ...extraItemEntries(checkoutItems.filter(item => !item.isTicket || item.isRequired), firstName, lastName, email),
            ],
        } as unknown as FinaliseOrderPayload;

        setStepErrors({});
        setCreationFailed(false);
        completeMutation.mutate({
            eventId: eventId as number,
            signature: `${checkoutItems.map(item => `${item.key}x${item.quantity}`).join(',')}:${promoCode ?? ''}`,
            pendingOrder,
            createPayload,
            completePayload,
            onOrderCreated: setPendingOrder,
        }, {
            onSuccess: navigateAfterCompletion,
            onError: handleServerError,
        });
    };

    const submitRegistration = () => {
        if (isPaidMode) {
            submitPaidOrder();
            return;
        }
        if (!ticket) {
            return;
        }
        const {order, products} = form.values;
        const quantity = products.length;
        const includedItems = requiredItems;

        const createPayload = {
            products: [
                {
                    product_id: Number(ticket.product.id),
                    quantities: [{price_id: Number(ticket.price.id), quantity}],
                },
                ...groupByProduct(includedItems),
            ],
            ...trackingFields(),
        } as ProductFormPayload;

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
            products: [
                ...products.map((participant, index) => ({
                    product_id: Number(ticket.product.id),
                    product_price_id: Number(ticket.price.id),
                    first_name: index === 0 ? order.first_name.trim() : participant.first_name.trim(),
                    last_name: index === 0 ? order.last_name.trim() : participant.last_name.trim(),
                    email,
                    email_confirmation: email,
                    birth_date: participant.birth_date,
                    questions: toAnswers(participant.questions),
                })),
                ...extraItemEntries(includedItems, order.first_name.trim(), order.last_name.trim(), email),
            ],
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
            onSuccess: navigateAfterCompletion,
            onError: handleServerError,
        });
    };

    const handleNext = () => {
        if (completeMutation.isPending) {
            return;
        }
        hasInteracted.current = true;
        const {_step: stepMessage, ...errors} = validateStep(step);
        if (stepMessage || Object.keys(errors).length > 0) {
            form.setErrors(errors);
            pendingFocus.current = 'error';
            setStepErrors(previous => ({...previous, [step.id]: stepMessage ?? ''}));
            return;
        }
        form.clearErrors();
        setStepErrors(previous => ({...previous, [step.id]: ''}));
        if (isLastStep) {
            submitRegistration();
            return;
        }
        const nextStep = steps[safeStepIndex + 1];
        const firstParticipant = form.values.products[0];
        if (isPaidMode && isPerTicket && nextStep?.kind === 'participants' && firstParticipant
            && !firstParticipant.first_name.trim() && !firstParticipant.last_name.trim()) {
            form.setFieldValue('products.0.first_name', form.values.order.first_name.trim());
            form.setFieldValue('products.0.last_name', form.values.order.last_name.trim());
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

    const changeQuantity = (key: string, delta: number) => {
        const line = checkoutLines.find(candidate => candidate.key === key);
        if (!line) {
            return;
        }
        hasInteracted.current = true;
        const current = Math.min(selection[key] ?? 0, line.max);
        const min = Math.max(1, Number(line.product.min_per_order) || 1);
        let next = current + delta;
        if (delta > 0 && current === 0) {
            next = min;
        } else if (delta < 0 && next < min) {
            next = 0;
        }
        next = Math.max(0, Math.min(next, line.max));
        setSelection(previous => ({...previous, [key]: next}));
        setStepErrors(previous => ({...previous, selection: ''}));
    };

    const removeParticipant = (index: number) => {
        form.removeListItem('products', index);
        pendingFocus.current = 'add-participant';
    };

    const productQuantityInSelection = (productId: unknown) => selectedLineItems
        .filter(item => Number(item.product.id) === Number(productId))
        .reduce((sum, item) => sum + item.quantity, 0);

    // A participant added on the participants step takes the ticket of the last participant row.
    const lastParticipantLineKey = form.values.products[form.values.products.length - 1]?.line_key;
    const addTargetItem = selectedTicketItems.find(item => item.key === lastParticipantLineKey)
        ?? selectedTicketItems[selectedTicketItems.length - 1];
    const addTargetLine = checkoutLines.find(line => line.key === addTargetItem?.key);
    const paidPlacesLeft = addTargetItem && addTargetLine
        ? Math.max(0, Math.min(
            addTargetLine.max - addTargetItem.quantity,
            getProductMaxPerOrder(addTargetItem.product) - productQuantityInSelection(addTargetItem.product.id),
        ))
        : 0;

    const addPaidParticipant = () => {
        if (!addTargetItem || paidPlacesLeft <= 0) {
            return;
        }
        hasInteracted.current = true;
        const participant: ParticipantValue = {
            key: nextParticipantKey(),
            line_key: addTargetItem.key,
            first_name: '',
            last_name: '',
            birth_date: '',
            questions: questionsForProduct(addTargetItem.product.id).map(emptyAnswer),
        };
        const lastIndexOfLine = form.values.products.map(row => row.line_key).lastIndexOf(addTargetItem.key);
        form.insertListItem('products', participant, lastIndexOfLine + 1);
        setSelection(previous => ({...previous, [addTargetItem.key]: addTargetItem.quantity + 1}));
        pendingFocus.current = participant.key;
    };

    const canRemoveParticipant = (participant: ParticipantValue, index: number) => {
        if (!isPaidMode) {
            return index > 0 && form.values.products.length > (ticket?.minParticipants ?? 1);
        }
        const item = selectedTicketItems.find(candidate => candidate.key === participant.line_key);
        if (!item || selectedTicketCount <= 1) {
            return false;
        }
        const min = Math.max(1, Number(item.product.min_per_order) || 1);
        return productQuantityInSelection(item.product.id) - 1 >= min;
    };

    const removeParticipantAt = (index: number) => {
        const participant = form.values.products[index];
        if (!participant || !canRemoveParticipant(participant, index)) {
            return;
        }
        hasInteracted.current = true;
        setConfirmingRemovalKey(null);
        if (!isPaidMode) {
            removeParticipant(index);
            return;
        }
        const item = selectedTicketItems.find(candidate => candidate.key === participant.line_key);
        if (!item) {
            return;
        }
        form.removeListItem('products', index);
        setSelection(previous => ({...previous, [item.key]: item.quantity - 1}));
        pendingFocus.current = 'add-participant';
    };

    const requestParticipantRemoval = (index: number) => {
        const participant = form.values.products[index];
        if (!participant) {
            return;
        }
        if (participant.first_name.trim() || participant.last_name.trim()) {
            setConfirmingRemovalKey(participant.key);
            pendingFocus.current = `confirm-${participant.key}`;
            return;
        }
        removeParticipantAt(index);
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

    const applyPromoCode = (code: string) => {
        promoMutation.mutate(code, {
            onSuccess: (result) => {
                if (!result.valid) {
                    setCodeError(t`That promo code is invalid`);
                    return;
                }
                setEvent(result.event);
                setPromoCode(code);
                setCodeOpen(false);
                setCodeValue('');
                setPendingOrder(null);
                addQueryStringToUrl('promo_code', code);
            },
            onError: () => setCodeError(t`That promo code is invalid`),
        });
    };

    const applyCode = () => {
        const code = codeValue.trim();
        if (code.length < 3) {
            setCodeError(isPaidMode ? t`That promo code is invalid` : t`This invitation code is not valid.`);
            return;
        }
        setCodeError(null);
        if (isPaidMode) {
            applyPromoCode(code);
            return;
        }
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

    const refreshAfterSaleStart = () => {
        let attempts = 0;
        const refresh = () => {
            attempts++;
            eventsClientPublic.findByID(eventId, promoCode)
                .then(({data}) => {
                    const hasOpenTicket = isPaidMode
                        ? getEmbeddedCheckoutLines(data).some(line => line.isTicket)
                        : !!resolveEmbeddedRegistrationTicket(data, promoCode ? publicProductIds : productIdsOf(data));
                    const stillClosed = getEventProducts(data).some(product => product.is_before_sale_start_date)
                        && !hasOpenTicket;
                    if (isPaidMode && hasOpenTicket) {
                        setSelection(previous => Object.keys(previous).length ? previous : initialSelection(data));
                    }
                    setEvent(data);
                    if (!promoCode) {
                        setPublicProductIds(productIdsOf(data));
                    }
                    if (stillClosed && attempts < 6) {
                        window.setTimeout(refresh, 2000);
                    }
                })
                .catch(() => {
                    if (attempts < 6) {
                        window.setTimeout(refresh, 2000);
                    }
                });
        };
        refresh();
    };

    const autoFilledProductIds = new Set([
        ...checkoutLines.filter(line => !line.isTicket).map(line => Number(line.product.id)),
        ...getRequiredCheckoutItems(event, 1).map(item => Number(item.product.id)),
    ]);
    const checkoutProductIds = new Set([...checkoutLines.map(line => Number(line.product.id)), ...autoFilledProductIds]);
    const relevantQuestions = isPaidMode
        ? [...orderQuestions, ...productQuestions.filter(question => question.product_ids?.some(id => checkoutProductIds.has(Number(id))))]
        : [...orderQuestions, ...participantQuestions];
    const hasAutoFilledProductQuestions = productQuestions.some(question =>
        question.product_ids?.some(id => autoFilledProductIds.has(Number(id)))
    );
    const hasNothingToRegister = isPaidMode
        ? ticketLines.length === 0
        : publicProductIds !== null && !ticket;
    const cannotEmbed = questionsQuery.isError
        || (questionsQuery.isFetched && hasUnsupportedQuestions(relevantQuestions))
        || (questionsQuery.isFetched && hasAutoFilledProductQuestions)
        || !!event.settings?.require_billing_address
        || (isPaidMode && hasUnsupportedCheckoutProducts(event))
        || hasNothingToRegister;

    const upcomingSaleStart = (event.product_categories?.flatMap(category => category.products || []) || event.products || [])
        .filter(product => product.is_before_sale_start_date && product.sale_start_date)
        .map(product => dayjs.utc(product.sale_start_date as string).toDate())
        .sort((a, b) => a.getTime() - b.getTime())[0];
    const rootClassName = classNames(classes.root, className);

    if (!forceClassic && hasNothingToRegister && upcomingSaleStart) {
        return (
            <section className={rootClassName} data-variant={variant} ref={sectionRef} id="tickets" aria-label={t`Registration`}>
                <div className={classNames(classes.panel, classes.comingSoon)}>
                    <SaleCountdown
                        opensAt={upcomingSaleStart}
                        timezone={event.timezone || undefined}
                        onReached={refreshAfterSaleStart}
                    />
                    {onAlreadyRegistered && (
                        <div className={classes.alreadyRegisteredRow}>
                            <button type="button" className={classes.linkButton} onClick={onAlreadyRegistered}>
                                {t`Already registered? Manage my attendance`}
                            </button>
                        </div>
                    )}
                </div>
            </section>
        );
    }

    if (forceClassic || cannotEmbed) {
        return <>{renderFallback()}</>;
    }

    const isLoading = !questionsQuery.isFetched || (isPaidMode ? false : publicProductIds === null || !ticket);
    const totalSteps = steps.length;
    const currentStepNumber = safeStepIndex + 1;
    const progress = totalSteps > 1 ? safeStepIndex / (totalSteps - 1) : 0;
    const titleId = `embedded-registration-${step.id}-title`;
    const participantsCount = form.values.products.length;
    const maxParticipants = isPaidMode ? participantsCount + paidPlacesLeft : ticket?.maxParticipants ?? 1;

    const lineLabel = ({product, price}: { product: Product, price: ProductPrice }) =>
        price.label ? `${product.title} (${price.label})` : product.title;

    const priceWithoutFees = (price: ProductPrice) => {
        const fee = Number(price.fee_total) || 0;
        return price.price_including_taxes_and_fees !== undefined && price.price_including_taxes_and_fees !== null
            ? Number(price.price_including_taxes_and_fees) - fee
            : Number(price.price) + (Number(price.tax_total) || 0);
    };

    const renderRunningTotal = () => (
        <>
            <div className={classes.runningTotal}>
                <span>{t`Total`}</span>
                <strong>{formatAmount(totalWithoutFees)}</strong>
            </div>
            {hasServiceFees && (
                <p className={classes.feesHint}>{t`+ service fees, detailed before payment`}</p>
            )}
        </>
    );

    const renderRequiredBadge = () => (
        <span className={classes.requiredBadge}>
            <IconLock size={12} aria-hidden="true"/>
            {t`Included by default`}
        </span>
    );

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
                            comboboxProps={variant === 'card' ? {withinPortal: false} : undefined}
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
                            {isPaidMode
                                ? (isPerTicket ? t`Enter the name of each participant.` : t`A few details for each participant.`)
                                : maxParticipants > 1
                                    ? t`You can register up to ${maxParticipants} people, including yourself.`
                                    : t`This registration is for one person.`}
                        </p>
                        <ol className={classes.participants}>
                            {form.values.products.map((participant, index) => {
                                const participantNumber = index + 1;
                                const buyerName = `${form.values.order.first_name} ${form.values.order.last_name}`.trim();
                                const lineTitle = isPaidMode && selectedTicketItems.length > 1
                                    ? selectedTicketItems.find(item => item.key === participant.line_key)?.product.title
                                    : undefined;
                                const showNameFields = isPaidMode ? isPerTicket : index > 0;
                                return (
                                    <li key={participant.key} className={classes.participant}>
                                        <div className={classes.participantHeader}>
                                            <span className={classes.participantNumber} aria-hidden="true">{participantNumber}</span>
                                            <h3 className={classes.participantTitle}>
                                                {index === 0 && !isPaidMode
                                                    ? <>{buyerName} <span className={classes.participantTag}>{t`you`}</span></>
                                                    : t`Participant ${participantNumber}`}
                                                {lineTitle && <span className={classes.participantProduct}>{lineTitle}</span>}
                                            </h3>
                                            {canRemoveParticipant(participant, index) && confirmingRemovalKey !== participant.key && (
                                                <ActionIcon
                                                    variant="subtle"
                                                    className={classes.removeButton}
                                                    onClick={() => requestParticipantRemoval(index)}
                                                    aria-label={t`Remove participant ${participantNumber}`}
                                                    title={t`Remove`}
                                                    data-focus-key={`remove-${participant.key}`}
                                                >
                                                    <IconX size={18}/>
                                                </ActionIcon>
                                            )}
                                            {canRemoveParticipant(participant, index) && confirmingRemovalKey === participant.key && (
                                                <div className={classes.removeConfirm} role="group" aria-label={t`Remove participant ${participantNumber}`}>
                                                    <span className={classes.removeConfirmLabel}>{t`Remove?`}</span>
                                                    <button
                                                        type="button"
                                                        className={classNames(classes.removeConfirmButton, classes.removeConfirmYes)}
                                                        onClick={() => removeParticipantAt(index)}
                                                        data-focus-key={`confirm-${participant.key}`}
                                                    >
                                                        {t`Yes`}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={classes.removeConfirmButton}
                                                        onClick={() => {
                                                            setConfirmingRemovalKey(null);
                                                            pendingFocus.current = `remove-${participant.key}`;
                                                        }}
                                                    >
                                                        {t`Cancel`}
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                        {showNameFields && (
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
                                        {questionsForParticipant(participant).map((question, questionIndex) => (
                                            <div key={question.id} className={classes.participantQuestion}>
                                                {renderQuestion(question, `products.${index}.questions.${questionIndex}`, false)}
                                            </div>
                                        ))}
                                    </li>
                                );
                            })}
                        </ol>
                        {!isPaidMode && maxParticipants > 1 && (
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
                        {isPaidMode && addTargetItem && (
                            <div className={classes.addRow}>
                                <button
                                    type="button"
                                    className={classes.addButton}
                                    onClick={addPaidParticipant}
                                    disabled={paidPlacesLeft <= 0}
                                    data-focus-key="add-participant"
                                >
                                    <IconPlus size={18} aria-hidden="true"/>
                                    {t`Add a participant`}
                                </button>
                                <span className={classes.addHint}>
                                    {t`${participantsCount} of ${maxParticipants} places`}
                                </span>
                                {paidPlacesLeft <= 0 && (
                                    <span className={classes.addLimit} aria-live="polite">
                                        {t`Maximum number of places reached for this order.`}
                                    </span>
                                )}
                            </div>
                        )}
                        {isPaidMode && renderRunningTotal()}
                    </>
                );
            case 'selection':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`How many participants?`}</h2>
                        <p className={classes.stepHelp}>{t`Choose the number of places for each ticket.`}</p>
                        <ul className={classes.lines}>
                            {checkoutLines.map(line => {
                                const quantity = Math.min(selection[line.key] ?? 0, line.max);
                                const label = lineLabel(line);
                                const unitPrice = priceWithoutFees(line.price);
                                return (
                                    <li key={line.key} className={classes.line}>
                                        <div className={classes.lineInfo}>
                                            <span className={classes.lineTitle}>{label}</span>
                                            <span className={classes.linePrice}>
                                                {unitPrice > 0 ? formatAmount(unitPrice) : t`Free`}
                                            </span>
                                        </div>
                                        <div className={classes.stepper} role="group" aria-label={t`Quantity for ${label}`}>
                                            <button
                                                type="button"
                                                className={classes.stepperButton}
                                                onClick={() => changeQuantity(line.key, -1)}
                                                disabled={quantity <= 0}
                                                aria-label={t`Remove one`}
                                            >
                                                <IconMinus size={18} aria-hidden="true"/>
                                            </button>
                                            <span className={classes.stepperValue} aria-live="polite">{quantity}</span>
                                            <button
                                                type="button"
                                                className={classes.stepperButton}
                                                onClick={() => changeQuantity(line.key, 1)}
                                                disabled={quantity >= line.max}
                                                aria-label={t`Add one`}
                                            >
                                                <IconPlus size={18} aria-hidden="true"/>
                                            </button>
                                        </div>
                                        <LineDescription html={line.product.description}/>
                                    </li>
                                );
                            })}
                            {selectedTicketCount > 0 && requiredItems.map(item => (
                                <li key={item.key} className={classNames(classes.line, classes.lineLocked)}>
                                    <div className={classes.lineInfo}>
                                        <span className={classes.lineTitle}>{lineLabel(item)}</span>
                                        <span className={classes.linePrice}>
                                            {formatAmount(priceWithoutFees(item.price))}
                                            {renderRequiredBadge()}
                                        </span>
                                    </div>
                                    <div className={classes.stepper} aria-label={t`Quantity`}>
                                        <span className={classes.stepperValue}>{item.quantity}</span>
                                    </div>
                                    <LineDescription html={item.product.description}/>
                                </li>
                            ))}
                        </ul>
                        {renderRunningTotal()}
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
                            {requiredItems.length > 0 && (
                                <div>
                                    <dt>{t`Included by default`}</dt>
                                    <dd>
                                        {requiredItems.map(item => (
                                            <span key={item.key} className={classes.summaryLine}>{lineLabel(item)}</span>
                                        ))}
                                    </dd>
                                </div>
                            )}
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
                        {renderCheckoutNotices()}
                    </>
                );
            case 'summary':
                return (
                    <>
                        <h2 className={classes.stepTitle} id={titleId}>{t`Your order`}</h2>
                        <p className={classes.stepHelp}>{t`Check your order before continuing to payment.`}</p>
                        <div className={classes.receipt}>
                            <ul className={classes.receiptLines}>
                                {checkoutItems.map(item => (
                                    <li key={item.key} className={classes.receiptLine}>
                                        <span className={classes.receiptLabel}>
                                            <span>{`${item.quantity} × ${lineLabel(item)}`}</span>
                                            {item.isRequired && renderRequiredBadge()}
                                        </span>
                                        <span className={classes.receiptAmount}>
                                            {formatAmount(Number(item.price.price) * item.quantity)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <dl className={classes.receiptTotals}>
                                {totals.fees > 0 && (
                                    <div>
                                        <dt>{totals.feeNames.length === 1 ? totals.feeNames[0] : t`Fees`}</dt>
                                        <dd>{formatAmount(totals.fees)}</dd>
                                    </div>
                                )}
                                {totals.taxes > 0 && (
                                    <div>
                                        <dt>{t`Taxes`}</dt>
                                        <dd>{formatAmount(totals.taxes)}</dd>
                                    </div>
                                )}
                                <div className={classes.receiptTotal}>
                                    <dt>{t`Total`}</dt>
                                    <dd>{formatAmount(totals.total)}</dd>
                                </div>
                            </dl>
                        </div>
                        <div className={classes.summaryCode}>{renderCodeRow()}</div>
                        <dl className={classes.summary}>
                            <div>
                                <dt>{t`Contact`}</dt>
                                <dd>{form.values.order.first_name} {form.values.order.last_name}<br/>{form.values.order.email}</dd>
                            </div>
                            {isPerTicket && form.values.products.length > 0 && (
                                <div>
                                    <dt>{t`Participants`}</dt>
                                    <dd>
                                        {form.values.products.map(participant => (
                                            <span key={participant.key} className={classes.summaryLine}>
                                                {`${participant.first_name} ${participant.last_name}`}
                                            </span>
                                        ))}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {renderCheckoutNotices()}
                    </>
                );
        }
    };

    const renderCheckoutNotices = () => (
        <>
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

    const renderCodeRow = () => {
        if (promoCode) {
            return (
                <div className={classes.codeApplied}>
                    <span>
                        {isPaidMode
                            ? t`Code ${promoCode} applied`
                            : t`Invitation code ${promoCode} applied: ${ticket?.product.title}`}
                    </span>
                    <button type="button" className={classes.linkButton} onClick={removeCode} disabled={promoMutation.isPending}>
                        {t`Remove`}
                    </button>
                </div>
            );
        }
        if (codeOpen) {
            return (
                <div className={classes.codeForm}>
                    <TextInput
                        value={codeValue}
                        onChange={(changeEvent) => setCodeValue(changeEvent.currentTarget.value)}
                        onKeyDown={(keyEvent) => {
                            if (keyEvent.key === 'Enter') {
                                keyEvent.preventDefault();
                                keyEvent.stopPropagation();
                                applyCode();
                            }
                        }}
                        label={isPaidMode ? t`Promo code` : t`Invitation code`}
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
            );
        }
        return (
            <button
                type="button"
                className={classes.linkButton}
                onClick={() => {
                    hasInteracted.current = true;
                    setCodeOpen(true);
                }}
            >
                {isPaidMode ? t`Have a promo code?` : t`I have an invitation code`}
            </button>
        );
    };

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
                        {creationFailed && current.kind === (isPaidMode ? 'selection' : 'participants') && (
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
                        rightSection={isLast && !isPaidMode ? <IconCheck size={20}/> : <IconArrowRight size={20}/>}
                    >
                        {isLast ? (isPaidMode ? t`Continue to payment` : t`Confirm my registration`) : t`Next`}
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
        <section className={rootClassName} data-variant={variant} ref={sectionRef} id="tickets" aria-label={t`Registration`}>
            <div className={classes.panel} ref={panelRef}>
                {isLoading ? (
                    <p className={classes.loading} role="status">{t`Loading...`}</p>
                ) : (
                    <>
                        <div className={classes.header}>
                            <span className={classes.eyebrow}>
                                <IconTicket size={16} aria-hidden="true"/>
                                {isPaidMode ? t`Registration` : t`Free registration`}
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

                        {!isPaidMode && <div className={classes.codeRow}>{renderCodeRow()}</div>}
                        {onAlreadyRegistered && (
                            <div className={classes.alreadyRegisteredRow}>
                                <button type="button" className={classes.linkButton} onClick={onAlreadyRegistered}>
                                    {t`Already registered? Manage my attendance`}
                                </button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </section>
    );
};

export default EmbeddedRegistration;
