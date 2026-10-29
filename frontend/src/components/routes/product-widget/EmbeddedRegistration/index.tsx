import {FormEvent, KeyboardEvent, ReactNode, RefObject, useEffect, useMemo, useRef, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {useNavigate} from "react-router";
import {useMutation, useQuery} from "@tanstack/react-query";
import {useForm} from "@mantine/form";
import {ActionIcon, Button, Checkbox, Radio, Select, Textarea, TextInput} from "@mantine/core";
import {
    IconArrowLeft,
    IconArrowRight,
    IconBrandMastercard,
    IconBrandVisa,
    IconCheck,
    IconCornerDownLeft,
    IconLock,
    IconMinus,
    IconPlus,
    IconShieldCheck,
    IconShieldLock,
    IconX
} from "@tabler/icons-react";
import classNames from "classnames";
import {Event, Product, ProductPrice, Question, QuestionType} from "../../../../types.ts";
import {FinaliseOrderPayload, orderClientPublic, ProductFormPayload} from "../../../../api/order.client.ts";
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
import {
    getCheckoutRefundProtection,
    getIncludedRefundProtection,
    getPlainTextSummary,
    REFUND_PROTECTION_OFFERED_STATE,
    withIncludedRefundProtection,
} from "../../../../utilites/refundProtection.ts";
import {getContactCode} from "../../../../utilites/contactLinkAttribution.ts";
import {
    BuyerContact,
    CheckoutDraft,
    clearBuyerProfile,
    clearCheckoutDraft,
    confirmOrderOnDevice,
    DraftAnswer,
    DraftAwaitingPayment,
    readBuyerProfile,
    readCheckoutDraft,
    rememberPendingContact,
    saveBuyerProfile,
    writeCheckoutDraft,
} from "../../../../utilites/checkoutMemory.ts";
import {getStoredSessionIdentifier, storeSessionIdentifier} from "../../../../utilites/checkoutSession.ts";
import {useKeyboardAwareActionBar} from "../../../../hooks/useKeyboardAwareActionBar.ts";
import {getSessionIdentifier} from "../../../../utilites/sessionIdentifier.ts";
import {getOrderTrackingContext, getQrLinkCode} from "../../../../utilites/qrAttribution.ts";
import {addQueryStringToUrl, removeQueryStringFromUrl} from "../../../../utilites/helpers.ts";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {AnalyticsEvents, trackEvent} from "../../../../utilites/analytics.ts";
import {getPrivacyPolicyUrl, getTermsOfSaleUrl} from "../../../../utilites/branding.ts";
import {UserGeneratedContent} from "../../../common/UserGeneratedContent";
import {SaleCountdown} from "./SaleCountdown.tsx";
import {LineDescription} from "./LineDescription.tsx";
import {LowStockIndicator} from "../../../common/LowStockIndicator";
import {HighlightedPromo} from "./HighlightedPromo.tsx";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import classes from "./EmbeddedRegistration.module.scss";

dayjs.extend(utc);

type StepKind = 'selection' | 'name' | 'questions' | 'participants' | 'contact';

const LEGACY_CONTACT_STEP_IDS = ['email', 'phone', 'summary', 'confirm'];

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

interface DeliveryChannels {
    email: boolean;
    phone: boolean;
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
    delivery: DeliveryChannels;
    terms_accepted: boolean;
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

const toDraftAnswers = (answers: QuestionAnswerValue[]): DraftAnswer[] =>
    answers.map(answer => ({question_id: answer.question_id, answer: answer.response?.answer ?? ''}));

const fromDraftAnswers = (answers: DraftAnswer[]): QuestionAnswerValue[] =>
    answers
        .filter(answer => answer && typeof answer === 'object')
        .map(answer => ({
            question_id: Number(answer.question_id) || undefined,
            response: {answer: Array.isArray(answer.answer) ? answer.answer.map(String) : String(answer.answer ?? '')},
        }));

const sameText = (a?: string, b?: string) => !!a && !!b && a.trim().toLowerCase() === b.trim().toLowerCase();

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

/**
 * The peace of mind option and the amounts are offered on the payment page, not on the contact step.
 * Set to true to show them again on the contact step (the payment page then skips the option).
 */
const SHOW_PROTECTION_AND_TOTAL_ON_CONTACT_STEP = false;
const FORM_IN_PROGRESS_ATTR = 'data-picha-form-in-progress';

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
    const [protectionOptIn, setProtectionOptIn] = useState(false);
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
    const [memoryReady, setMemoryReady] = useState(false);
    const [restoreStepId, setRestoreStepId] = useState<string | null>(null);
    const [awaitingPayment, setAwaitingPayment] = useState<DraftAwaitingPayment | null>(null);
    const [rememberedProfile, setRememberedProfile] = useState<BuyerContact | null>(null);
    const profileRef = useRef<BuyerContact | null | undefined>(undefined);
    const latestDraft = useRef<CheckoutDraft | null>(null);
    const draftClosed = useRef(false);
    const getProfile = () => {
        if (profileRef.current === undefined) {
            profileRef.current = readBuyerProfile();
        }
        return profileRef.current;
    };

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
            delivery: {email: true, phone: false},
            terms_accepted: false,
        },
    });

    useEffect(() => {
        if (codeOpen && hasInteracted.current) {
            codeInputRef.current?.focus();
        }
    }, [codeOpen]);

    const questionsQuery = useGetEventQuestionsPublic(eventId);
    const highlightedPromoCode = (initialEvent.settings?.highlighted_promo_code ?? '').trim().toLowerCase() || null;
    const highlightedPromoQuery = useQuery({
        queryKey: ['embeddedRegistrationHighlightedPromo', eventId, highlightedPromoCode],
        queryFn: () => promoCodeClientPublic.validateCode(eventId, highlightedPromoCode),
        enabled: isPaidMode && !!highlightedPromoCode,
        refetchOnWindowFocus: false,
        retry: 0,
    });
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
    const checkoutProtection = isPaidMode && !event.settings?.single_product_per_order
        ? getCheckoutRefundProtection(getEventProducts(event))
        : null;
    const countTickets = (items: EmbeddedCheckoutItem[]) => items.reduce((sum, item) => sum + item.quantity, 0);
    const coveredTicketCount = countTickets(selectedTicketItems.filter(item => getIncludedRefundProtection(item.price) > 0));
    const otherTicketItems = selectedTicketItems.filter(item => getIncludedRefundProtection(item.price) <= 0);
    const optionalProtectionQuantity = checkoutProtection
        && !checkoutProtection.product.is_required
        && otherTicketItems.some(item => Number(item.price.price) > 0)
        ? (checkoutProtection.product.required_quantity_mode === 'PER_TICKET'
            ? countTickets(otherTicketItems)
            : Math.max(1, Number(checkoutProtection.product.min_per_order) || 1))
        : 0;
    const protectionItem = (key: string, quantity: number, isRequired: boolean): EmbeddedCheckoutItem[] =>
        checkoutProtection && quantity > 0 ? [{
            key,
            product: checkoutProtection.product,
            price: checkoutProtection.price,
            quantity,
            isTicket: false,
            isRequired,
        }] : [];
    const protectionItems: EmbeddedCheckoutItem[] = [
        ...protectionItem('refund-protection-included', coveredTicketCount, true),
        ...(SHOW_PROTECTION_AND_TOTAL_ON_CONTACT_STEP && protectionOptIn ? protectionItem('refund-protection-optional', optionalProtectionQuantity, false) : []),
    ];
    const isProtectionAdded = SHOW_PROTECTION_AND_TOTAL_ON_CONTACT_STEP && protectionOptIn && optionalProtectionQuantity > 0;
    const totals = computeCheckoutTotals([...checkoutItems, ...protectionItems]);
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

    const alignOrderAnswers = (existing: QuestionAnswerValue[]) => orderQuestions.map(question => {
        const answer = existing.find(candidate => candidate.question_id === question.id) ?? emptyAnswer(question);
        const profilePhone = question.type === QuestionType.PHONE ? getProfile()?.phone : undefined;
        return profilePhone && isAnswerEmpty(answer.response.answer)
            ? {...answer, response: {answer: profilePhone}}
            : answer;
    });

    useEffect(() => {
        form.setFieldValue('order.questions', alignOrderAnswers(form.getValues().order.questions));
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

    useEffect(() => {
        const draft = readCheckoutDraft(eventId);
        const profile = getProfile();
        setRememberedProfile(profile);
        const pick = (draftValue: string | undefined, profileValue: string | undefined) => draftValue || profileValue || '';

        if (draft) {
            if (isPaidMode) {
                const restored: Record<string, number> = {};
                getEmbeddedCheckoutLines(event).forEach(line => {
                    const quantity = Math.floor(Number(draft.selection[line.key]) || 0);
                    if (quantity > 0) {
                        restored[line.key] = Math.min(quantity, line.max);
                    }
                });
                if (Object.keys(restored).length > 0) {
                    setSelection(restored);
                }
            }
            setProtectionOptIn(draft.protectionOptIn);
            const draftAnswers = fromDraftAnswers(draft.order.questions);
            const previous = form.getValues();
            form.setValues({
                order: {
                    ...previous.order,
                    first_name: pick(draft.order.first_name, profile?.first_name),
                    last_name: pick(draft.order.last_name, profile?.last_name),
                    email: pick(draft.order.email, profile?.email),
                    opted_into_marketing: draft.order.opted_into_marketing,
                    questions: questionsQuery.isFetched ? alignOrderAnswers(draftAnswers) : draftAnswers,
                },
                products: draft.participants.length > 0
                    ? draft.participants.map((participant, index) => ({
                        key: index === 0 && !isPaidMode ? 'participant-buyer' : nextParticipantKey(),
                        line_key: participant.line_key || undefined,
                        first_name: String(participant.first_name ?? ''),
                        last_name: String(participant.last_name ?? ''),
                        birth_date: String(participant.birth_date ?? ''),
                        questions: fromDraftAnswers(Array.isArray(participant.questions) ? participant.questions : []),
                    }))
                    : previous.products,
            });
            setRestoreStepId(LEGACY_CONTACT_STEP_IDS.includes(draft.stepId) ? 'contact' : (draft.stepId || null));
            if (draft.awaitingPayment) {
                const pending = draft.awaitingPayment;
                setAwaitingPayment(pending);
                orderClientPublic.findByShortId(Number(eventId), pending.shortId, [], pending.sessionIdentifier)
                    .then(({data}) => {
                        if (data.status === 'COMPLETED' || data.status === 'AWAITING_OFFLINE_PAYMENT') {
                            confirmOrderOnDevice(eventId as number, pending.shortId);
                            setRememberedProfile(readBuyerProfile());
                            setAwaitingPayment(null);
                            setRestoreStepId(null);
                            setStepIndex(0);
                            return;
                        }
                        if (data.status !== 'RESERVED' || data.is_expired) {
                            setAwaitingPayment(null);
                            return;
                        }
                        if (pending.sessionIdentifier && !getStoredSessionIdentifier()) {
                            storeSessionIdentifier(pending.sessionIdentifier);
                        }
                    })
                    .catch(() => setAwaitingPayment(null));
            }
        } else if (profile) {
            const previous = form.getValues();
            form.setValues({
                ...previous,
                order: {
                    ...previous.order,
                    first_name: pick(previous.order.first_name, profile.first_name),
                    last_name: pick(previous.order.last_name, profile.last_name),
                    email: pick(previous.order.email, profile.email),
                },
            });
        }
        setMemoryReady(true);
    }, []);

    const needsParticipantsStep = !isPaidMode
        || isPerTicket
        || isAgeCheckEnabled
        || selectedTicketItems.some(item => questionsForProduct(item.product.id).length > 0);

    const phoneQuestionIndex = orderQuestions.findIndex(question => question.type === QuestionType.PHONE);
    const phoneQuestion = phoneQuestionIndex >= 0 ? orderQuestions[phoneQuestionIndex] : null;
    const isPhoneSelected = !!phoneQuestion;

    const steps = useMemo<Step[]>(() => {
        const result: Step[] = [
            ...(isPaidMode ? [{id: 'selection', kind: 'selection' as StepKind, questionIndexes: [], consentIndexes: []}] : []),
            {id: 'name', kind: 'name', questionIndexes: [], consentIndexes: []},
        ];
        const contact: Step = {
            id: 'contact',
            kind: 'contact',
            questionIndexes: phoneQuestionIndex >= 0 ? [phoneQuestionIndex] : [],
            consentIndexes: [],
        };
        orderQuestions.forEach((question, index) => {
            if (index === phoneQuestionIndex) {
                return;
            }
            if (isSingleConsentQuestion(question)) {
                contact.consentIndexes.push(index);
                return;
            }
            result.push({id: `questions-${index}`, kind: 'questions', questionIndexes: [index], consentIndexes: []});
        });
        if (needsParticipantsStep) {
            result.push({id: 'participants', kind: 'participants', questionIndexes: [], consentIndexes: []});
        }
        result.push(contact);
        return result;
    }, [orderQuestions, isPaidMode, needsParticipantsStep, phoneQuestionIndex]);

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

        const viewport = window.visualViewport;
        if (target !== container && window.matchMedia?.('(max-width: 600px)').matches) {
            const visibleTop = viewport ? viewport.offsetTop : 0;
            const visibleHeight = viewport ? viewport.height : window.innerHeight;
            const fieldTop = target.getBoundingClientRect().top;
            const wanted = visibleTop + visibleHeight * 0.3;
            if (Math.abs(fieldTop - wanted) > visibleHeight * 0.2) {
                window.scrollBy({top: fieldTop - wanted, behavior: 'auto'});
            }
            return;
        }

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
            if (order.first_name.length > 40) errors['order.first_name'] = t`First name must be under 40 characters`;
            if (order.last_name.length > 40) errors['order.last_name'] = t`Last name must be under 40 characters`;
        }

        if (current.kind === 'contact') {
            if (!EMAIL_PATTERN.test(order.email.trim())) {
                errors['order.email'] = t`A valid email is required`;
            }
            if (isPhoneSelected && phoneQuestion && isAnswerEmpty(form.values.order.questions[phoneQuestionIndex]?.response.answer)) {
                errors[`order.questions.${phoneQuestionIndex}.response.answer`] = requiredMessage;
            }
            if (!form.values.terms_accepted) {
                errors['terms_accepted'] = t`Please accept the terms of sale and the privacy policy.`;
            }
        }

        [...current.questionIndexes, ...current.consentIndexes]
            .filter(index => index !== phoneQuestionIndex || isPhoneSelected)
            .forEach(index => validateQuestion(orderQuestions[index], `order.questions.${index}`, errors));

        if (current.kind === 'participants') {
            products.forEach((participant, index) => {
                if (isPaidMode ? isPerTicket : index > 0) {
                    if (!participant.first_name.trim()) errors[`products.${index}.first_name`] = requiredMessage;
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
        if (key.startsWith('order.email')) return indexOf('contact');
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

    const orderAnswersForSubmit = () => toAnswers(form.values.order.questions.map((answer, index) =>
        index === phoneQuestionIndex && !isPhoneSelected ? {...answer, response: {answer: ''}} : answer
    ));

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

    const paymentPageState = () => ({
        [REFUND_PROTECTION_OFFERED_STATE]: SHOW_PROTECTION_AND_TOTAL_ON_CONTACT_STEP
            && !!checkoutProtection && (coveredTicketCount > 0 || optionalProtectionQuantity > 0),
    });

    const navigateAfterCompletion = (
        completedOrder: { is_payment_required?: boolean, short_id: string, reserved_until?: string },
        resumeSignature?: string,
    ) => {
        const contact = buyerContact();
        draftClosed.current = true;
        if (!completedOrder.is_payment_required) {
            trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_FREE);
            clearCheckoutDraft(eventId as number);
            saveBuyerProfile(contact);
        } else {
            rememberPendingContact(completedOrder.short_id, contact);
            writeCheckoutDraft(eventId as number, buildDraft(step.id, {
                shortId: completedOrder.short_id,
                reservedUntil: completedOrder.reserved_until,
                sessionIdentifier: getStoredSessionIdentifier() ?? undefined,
                signature: resumeSignature ?? '',
            }), eventEnd);
        }
        const nextPage = completedOrder.is_payment_required ? 'payment' : 'summary';
        navigate(eventCheckoutPath(eventId as number, completedOrder.short_id, nextPage), {state: paymentPageState()});
    };

    const submitPaidOrder = () => {
        const {order, products} = form.values;
        const firstName = order.first_name.trim();
        const lastName = order.last_name.trim();
        const email = order.email.trim();

        const createPayload = {
            products: [
                ...groupByProduct(checkoutItems),
                ...(isProtectionAdded && checkoutProtection ? [{
                    product_id: Number(checkoutProtection.product.id),
                    quantities: [{price_id: Number(checkoutProtection.price.id), quantity: 1}],
                }] : []),
            ],
            ...trackingFields(),
        } as ProductFormPayload;

        const completePayload = {
            order: {
                first_name: firstName,
                last_name: lastName,
                email,
                email_confirmation: email,
                address: {},
                questions: orderAnswersForSubmit(),
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

        const signature = `${checkoutItems.map(item => `${item.key}x${item.quantity}`).join(',')}:${promoCode ?? ''}:${isProtectionAdded ? 'protection' : ''}`;
        const resumeSignature = `${signature}#${JSON.stringify(completePayload)}`;

        if (awaitingPayment) {
            const stillReserved = !awaitingPayment.reservedUntil
                || dayjs.utc(awaitingPayment.reservedUntil).valueOf() > Date.now() + 30_000;
            if (stillReserved && awaitingPayment.signature === resumeSignature) {
                draftClosed.current = true;
                navigate(eventCheckoutPath(eventId as number, awaitingPayment.shortId, 'payment'), {state: paymentPageState()});
                return;
            }
            if (stillReserved) {
                orderClientPublic.abandonOrder(eventId, awaitingPayment.shortId).catch(() => undefined);
            }
            setAwaitingPayment(null);
        }

        setStepErrors({});
        setCreationFailed(false);
        completeMutation.mutate({
            eventId: eventId as number,
            signature,
            pendingOrder,
            createPayload,
            completePayload,
            onOrderCreated: setPendingOrder,
        }, {
            onSuccess: completedOrder => navigateAfterCompletion(completedOrder, resumeSignature),
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
                questions: orderAnswersForSubmit(),
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
            onSuccess: completedOrder => navigateAfterCompletion(completedOrder),
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

    useEffect(() => {
        if (!memoryReady || restoreStepId === null || !questionsQuery.isFetched || (!isPaidMode && !ticket)) {
            return;
        }
        const answers = form.values.order.questions;
        if (answers.length !== orderQuestions.length
            || orderQuestions.some((question, index) => answers[index]?.question_id !== question.id)) {
            return;
        }
        let target = Math.max(0, steps.findIndex(candidate => candidate.id === restoreStepId));
        for (let index = 0; index < target; index++) {
            const {_step: stepMessage, ...errors} = validateStep(steps[index]);
            if (stepMessage || Object.keys(errors).length > 0) {
                target = index;
                break;
            }
        }
        setRestoreStepId(null);
        setStepIndex(target);
    });

    const buyerPhone = () => {
        const answer = isPhoneSelected ? form.values.order.questions[phoneQuestionIndex]?.response.answer : '';
        return typeof answer === 'string' ? answer.trim() : '';
    };

    const buyerContact = (): BuyerContact => ({
        first_name: form.values.order.first_name.trim(),
        last_name: form.values.order.last_name.trim(),
        email: form.values.order.email.trim(),
        phone: buyerPhone(),
    });

    const buildDraft = (stepId: string, pendingPayment: DraftAwaitingPayment | null): CheckoutDraft => ({
        stepId,
        selection,
        order: {
            first_name: form.values.order.first_name,
            last_name: form.values.order.last_name,
            email: form.values.order.email,
            opted_into_marketing: form.values.order.opted_into_marketing,
            questions: toDraftAnswers(form.values.order.questions),
        },
        participants: form.values.products.map(participant => ({
            line_key: participant.line_key,
            first_name: participant.first_name,
            last_name: participant.last_name,
            birth_date: participant.birth_date,
            questions: toDraftAnswers(participant.questions),
        })),
        protectionOptIn,
        delivery: form.values.delivery,
        awaitingPayment: pendingPayment,
    });

    const eventEnd = event.end_date || (event.start_date ? dayjs.utc(event.start_date).add(1, 'day').toISOString() : null);
    const hasDraftContent = safeStepIndex > 0
        || hasInteracted.current
        || !!awaitingPayment
        || [form.values.order.first_name, form.values.order.last_name, form.values.order.email]
            .some(value => value.trim() && !(rememberedProfile && [rememberedProfile.first_name, rememberedProfile.last_name, rememberedProfile.email].includes(value.trim())));
    latestDraft.current = memoryReady && restoreStepId === null && !draftClosed.current && hasDraftContent
        ? buildDraft(step.id, awaitingPayment)
        : null;

    useEffect(() => {
        if (!latestDraft.current) {
            return;
        }
        const timer = window.setTimeout(() => {
            if (latestDraft.current && !draftClosed.current) {
                writeCheckoutDraft(eventId as number, latestDraft.current, eventEnd);
            }
        }, 400);
        return () => window.clearTimeout(timer);
    }, [memoryReady, restoreStepId, step.id, selection, form.values, protectionOptIn, awaitingPayment]);

    useEffect(() => {
        const flush = () => {
            if (latestDraft.current && !draftClosed.current) {
                writeCheckoutDraft(eventId as number, latestDraft.current, eventEnd);
            }
        };
        const handleVisibility = () => {
            if (document.visibilityState === 'hidden') {
                flush();
            }
        };
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', handleVisibility);
        return () => {
            window.removeEventListener('pagehide', flush);
            document.removeEventListener('visibilitychange', handleVisibility);
        };
    }, [eventId, eventEnd]);

    const forgetRememberedProfile = () => {
        const profile = rememberedProfile;
        clearBuyerProfile();
        profileRef.current = null;
        setRememberedProfile(null);
        if (!profile) {
            return;
        }
        const previous = form.getValues();
        const {order} = previous;
        form.setValues({
            ...previous,
            order: {
                ...order,
                first_name: sameText(order.first_name, profile.first_name) ? '' : order.first_name,
                last_name: sameText(order.last_name, profile.last_name) ? '' : order.last_name,
                email: sameText(order.email, profile.email) ? '' : order.email,
                questions: order.questions.map(answer => {
                    const question = orderQuestions.find(candidate => candidate.id === answer.question_id);
                    return question?.type === QuestionType.PHONE && sameText(String(answer.response.answer), profile.phone)
                        ? {...answer, response: {answer: ''}}
                        : answer;
                }),
            },
        });
        pendingFocus.current = 'step';
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

    const applyPromoCode = (code: string, onInvalid?: () => void) => {
        promoMutation.mutate(code, {
            onSuccess: (result) => {
                if (!result.valid) {
                    setCodeError(t`That promo code is invalid`);
                    onInvalid?.();
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

    useKeyboardAwareActionBar(panelRef, `.${classes.nav}`, !forceClassic && !cannotEmbed && questionsQuery.isFetched);

    const [isPanelOnScreen, setIsPanelOnScreen] = useState(false);
    useEffect(() => {
        const panel = panelRef.current;
        if (!panel || typeof IntersectionObserver === 'undefined') {
            return;
        }
        const observer = new IntersectionObserver(([entry]) => setIsPanelOnScreen(entry.isIntersecting), {threshold: 0.25});
        observer.observe(panel);
        return () => observer.disconnect();
    });

    const currentStepKind = steps[safeStepIndex]?.kind;
    const formInProgress = !forceClassic && !cannotEmbed && isPanelOnScreen
        && currentStepKind !== undefined && currentStepKind !== 'selection' && currentStepKind !== 'name';
    useEffect(() => {
        if (!formInProgress || typeof document === 'undefined') {
            return;
        }
        const root = document.documentElement;
        root.setAttribute(FORM_IN_PROGRESS_ATTR, '');
        return () => root.removeAttribute(FORM_IN_PROGRESS_ATTR);
    }, [formInProgress]);

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

    const renderRunningTotal = (label?: string) => (
        <>
            <div className={classes.runningTotal}>
                <span>{label ?? t`Total`}</span>
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
                return <TextInput {...inputProps} {...common} type="tel" inputMode="tel" autoComplete={path.startsWith('order.') ? 'tel' : 'off'} name={path.startsWith('order.') ? 'tel' : undefined}/>;
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
                                name="given-name"
                                autoComplete="given-name"
                                autoCapitalize="words"
                                classNames={fieldClassNames}
                            />
                            <TextInput
                                {...form.getInputProps('order.last_name')}
                                label={t`Last name`}
                                name="family-name"
                                autoComplete="family-name"
                                autoCapitalize="words"
                                classNames={fieldClassNames}
                            />
                        </div>
                    </>
                );
            case 'contact':
                return (
                    <>
                        {renderBuyerIdentity()}
                        <h2 className={classes.stepTitle} id={titleId}>{t`Where should we send your tickets?`}</h2>
                        {isPaidMode && (
                            <div className={classes.summaryCode}>{renderCodeRow()}</div>
                        )}
                        <div className={phoneQuestion ? classes.fieldRow : classes.fieldStack}>
                            <TextInput
                                {...form.getInputProps('order.email')}
                                type="email"
                                label={t`Email Address`}
                                withAsterisk
                                name="email"
                                autoComplete="email"
                                inputMode="email"
                                autoCapitalize="off"
                                autoCorrect="off"
                                spellCheck={false}
                                classNames={fieldClassNames}
                            />
                            {phoneQuestion && (
                                <TextInput
                                    {...form.getInputProps(`order.questions.${phoneQuestionIndex}.response.answer`)}
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    name="tel"
                                    label={phoneQuestion.title}
                                    withAsterisk
                                    data-focus-key="delivery-phone"
                                    description={phoneQuestion.description
                                        ? <UserGeneratedContent dangerouslySetInnerHTML={{__html: phoneQuestion.description}}/>
                                        : undefined}
                                    classNames={fieldClassNames}
                                />
                            )}
                        </div>
                        {renderDeviceMemoryNote(step)}
                        {renderConsents(step)}
                        {renderAgreements()}
                        {isPaidMode && SHOW_PROTECTION_AND_TOTAL_ON_CONTACT_STEP && (
                            <>
                                {renderIncludedProtection()}
                                {renderProtectionOffer()}
                                {renderPayableTotal()}
                            </>
                        )}
                    </>
                );
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
                                                    label={t`Last name`}
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
                        <div className={classes.selectionCard}>
                            <ul className={classes.lines}>
                                {checkoutLines.map(line => {
                                    const quantity = Math.min(selection[line.key] ?? 0, line.max);
                                    const label = lineLabel(line);
                                    const unitPrice = withIncludedRefundProtection(priceWithoutFees(line.price), line.price);
                                    const formattedUnitPrice = formatAmount(unitPrice);
                                    return (
                                        <li key={line.key} className={classes.line}>
                                            <div className={classes.lineInfo}>
                                                <span className={classes.lineTitle}>{label}</span>
                                                <span className={classes.linePrice}>
                                                    {unitPrice > 0 ? t`${formattedUnitPrice} per place` : t`Free`}
                                                </span>
                                                <LowStockIndicator
                                                    price={line.price}
                                                    currency={event.currency}
                                                    unitPrice={unitPrice}
                                                    className={classes.lineLowStock}
                                                />
                                                <LineDescription html={line.product.description}/>
                                            </div>
                                            <div className={classes.stepper} role="group" aria-label={t`Quantity for ${label}`}>
                                                <button
                                                    type="button"
                                                    className={classes.stepperButton}
                                                    onClick={() => changeQuantity(line.key, -1)}
                                                    disabled={quantity <= 0}
                                                    aria-label={t`Remove one`}
                                                >
                                                    <IconMinus size={20} stroke={2.4} aria-hidden="true"/>
                                                </button>
                                                <span className={classes.stepperValue} aria-live="polite">{quantity}</span>
                                                <button
                                                    type="button"
                                                    className={classes.stepperButton}
                                                    onClick={() => changeQuantity(line.key, 1)}
                                                    disabled={quantity >= line.max}
                                                    aria-label={t`Add one`}
                                                >
                                                    <IconPlus size={20} stroke={2.4} aria-hidden="true"/>
                                                </button>
                                            </div>
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
                                            <LineDescription html={item.product.description}/>
                                        </div>
                                        <div className={classes.stepper} aria-label={t`Quantity`}>
                                            <span className={classes.stepperValue}>{item.quantity}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {renderRunningTotal(t`Total tickets`)}
                        </div>
                        {renderHighlightedPromo()}
                    </>
                );
        }
    };

    const renderProtectionOffer = () => {
        if (!checkoutProtection || optionalProtectionQuantity <= 0) {
            return null;
        }

        const amount = formatAmount(Math.round(Number(checkoutProtection.price.price) * optionalProtectionQuantity * 100) / 100);
        const label = coveredTicketCount > 0
            ? t`Add the peace of mind option to your other tickets for ${amount}`
            : t`Add the peace of mind option for ${amount}`;
        const hint = getPlainTextSummary(checkoutProtection.product.description);

        return (
            <label className={classes.protectionOffer} data-checked={protectionOptIn}>
                <IconShieldCheck size={22} className={classes.protectionIcon} aria-hidden="true"/>
                <span className={classes.protectionBody}>
                    <span className={classes.protectionLabel}>{label}</span>
                    {hint && <span className={classes.protectionHint}>{hint}</span>}
                </span>
                <Checkbox
                    checked={protectionOptIn}
                    onChange={(changeEvent) => setProtectionOptIn(changeEvent.currentTarget.checked)}
                    disabled={completeMutation.isPending}
                    aria-label={label}
                />
            </label>
        );
    };

    const consentClassNames = {
        root: classes.consent,
        body: classes.consentBody,
        label: classes.consentLabel,
        input: classes.consentInput,
        error: classes.fieldError,
    };

    const renderAgreements = () => (
        <div className={classes.consents}>
            <Checkbox
                {...form.getInputProps('terms_accepted', {type: 'checkbox'})}
                required
                color="var(--event-primary-color)"
                iconColor="var(--event-accent-contrast)"
                classNames={consentClassNames}
                label={(
                    <>
                        <Trans>
                            I accept the <a href={getTermsOfSaleUrl()} target="_blank" rel="noopener noreferrer">terms of sale</a> and the <a href={getPrivacyPolicyUrl()} target="_blank" rel="noopener noreferrer">privacy policy</a>
                        </Trans>
                        <span className={classes.fieldRequired} aria-hidden="true"> *</span>
                    </>
                )}
            />
            {event.settings?.show_marketing_opt_in !== false && (
                <Checkbox
                    {...form.getInputProps('order.opted_into_marketing', {type: 'checkbox'})}
                    color="var(--event-primary-color)"
                    iconColor="var(--event-accent-contrast)"
                    classNames={consentClassNames}
                    label={t`I would like to receive news and offers from the organizer`}
                />
            )}
        </div>
    );

    const renderPayableTotal = () => (
        <dl className={classNames(classes.receiptTotals, classes.payableTotal)}>
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
    );

    const renderBuyerIdentity = () => {
        const {order} = form.values;
        const name = `${order.first_name.trim()} ${order.last_name.trim()}`.trim();
        if (!name) {
            return null;
        }
        return (
            <p className={classes.identity}>
                <span className={classes.identityName}>{t`Tickets in the name of ${name}`}</span>
            </p>
        );
    };

    const renderIncludedProtection = () => {
        if (coveredTicketCount <= 0) {
            return null;
        }
        const hint = checkoutProtection ? getPlainTextSummary(checkoutProtection.product.description) : '';
        return (
            <div className={classes.protectionOffer} data-checked="true">
                <IconShieldCheck size={22} className={classes.protectionIcon} aria-hidden="true"/>
                <span className={classes.protectionBody}>
                    <span className={classes.protectionLabel}>{t`Peace of mind option included`}</span>
                    {hint && <span className={classes.protectionHint}>{hint}</span>}
                </span>
            </div>
        );
    };

    const renderHighlightedPromo = () => {
        const promo = highlightedPromoQuery.data;
        if (!highlightedPromoCode || !promo?.valid) {
            return null;
        }
        const isApplied = (promoCode ?? '').trim().toLowerCase() === highlightedPromoCode;
        if (promoCode && !isApplied) {
            return null;
        }
        const eligibleIds = (promo.applicable_product_ids ?? []).map(Number);
        if (eligibleIds.length > 0) {
            const candidates = (selectedTicketItems.length > 0 ? selectedTicketItems : ticketLines)
                .map(item => Number(item.product.id));
            if (!candidates.some(id => eligibleIds.includes(id))) {
                return null;
            }
        }
        return (
            <HighlightedPromo
                code={highlightedPromoCode}
                promo={promo}
                currency={currency}
                applied={isApplied}
                applying={promoMutation.isPending}
                onApply={() => {
                    hasInteracted.current = true;
                    applyPromoCode(highlightedPromoCode, () => highlightedPromoQuery.refetch());
                }}
            />
        );
    };

    const renderReassurance = () => (
        <div className={classes.reassurance}>
            {event.settings?.payment_providers?.includes('STRIPE') && (
                <span className={classes.reassuranceItem}>
                    <IconShieldLock size={16} aria-hidden="true"/>
                    {t`Secure payment by Stripe`}
                </span>
            )}
            <span className={classes.reassuranceItem}>
                <IconLock size={16} aria-hidden="true"/>
                {t`Data protected (GDPR)`}
            </span>
            {event.settings?.payment_providers?.includes('STRIPE') && (
                <span className={classes.reassuranceCards} role="img" aria-label={t`Accepted cards: Visa, Mastercard`}>
                    <IconBrandVisa size={22} aria-hidden="true"/>
                    <IconBrandMastercard size={22} aria-hidden="true"/>
                </span>
            )}
        </div>
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

    const renderDeviceMemoryNote = (current: Step) => {
        const profile = rememberedProfile;
        if (!profile) {
            return null;
        }
        const {order} = form.values;
        const isPrefilled = {
            name: sameText(order.first_name, profile.first_name) || sameText(order.last_name, profile.last_name),
            contact: sameText(order.email, profile.email) || sameText(buyerPhone(), profile.phone),
        }[current.kind as 'name' | 'contact'];
        if (!isPrefilled) {
            return null;
        }
        return (
            <p className={classes.deviceMemory}>
                <span>{t`Your details stay on this device.`}</span>
                <button type="button" className={classes.linkButton} onClick={forgetRememberedProfile}>
                    {t`Not me`}
                </button>
            </p>
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
                {current.kind !== 'contact' && renderDeviceMemoryNote(current)}
                {current.kind !== 'contact' && renderConsents(current)}
                {current.kind === 'contact' && isPaidMode && awaitingPayment && (
                    <p className={classes.resumeNotice}>{t`Your order is still reserved. Continue to payment to finish it.`}</p>
                )}

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

                {(index > 0 || (isLast && isPaidMode)) && (
                    <div className={classes.stepFooter}>
                        {index > 0 && (
                            <button
                                type="button"
                                className={classes.backButton}
                                onClick={() => goToStep(index - 1)}
                                disabled={completeMutation.isPending}
                            >
                                <IconArrowLeft size={16} aria-hidden="true"/>
                                {t`Previous`}
                            </button>
                        )}
                        {isLast && isPaidMode && renderReassurance()}
                    </div>
                )}

                <div className={classes.nav} data-step-kind={current.kind}>
                    <Button
                        type={active ? 'submit' : 'button'}
                        className={classes.nextButton}
                        loading={active && completeMutation.isPending}
                        leftSection={isLast && isPaidMode ? <IconLock size={18}/> : undefined}
                        rightSection={isLast && !isPaidMode ? <IconCheck size={20}/> : <IconArrowRight size={20}/>}
                    >
                        {isLast ? (isPaidMode ? t`Payment` : t`Confirm my registration`) : t`Next`}
                    </Button>
                    {!isLast && (
                        <span className={classes.enterHint} aria-hidden="true">
                            {t`or press Enter`} <IconCornerDownLeft size={14}/>
                        </span>
                    )}
                </div>

                {current.kind === 'contact' && !!event.settings?.pre_checkout_message && (
                    <UserGeneratedContent
                        className={classes.preCheckout}
                        dangerouslySetInnerHTML={{__html: event.settings.pre_checkout_message}}
                    />
                )}
            </>
        );
    };

    return (
        <section
            className={rootClassName}
            data-variant={variant}
            ref={sectionRef}
            id="tickets"
            aria-label={t`Registration`}
            aria-busy={isLoading || undefined}
        >
            <div className={classes.panel} ref={panelRef}>
                {isLoading ? (
                    <p className={classes.loading} role="status">{t`Loading...`}</p>
                ) : (
                    <>
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
