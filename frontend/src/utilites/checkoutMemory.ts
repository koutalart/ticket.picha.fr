import {IdParam} from "../types.ts";

const DAY_MS = 24 * 60 * 60 * 1000;
const DRAFT_TTL_MS = 7 * DAY_MS;
const PROFILE_TTL_MS = 365 * DAY_MS;
const PENDING_CONTACT_TTL_MS = 2 * DAY_MS;
const DRAFT_VERSION = 1;

const draftKey = (eventId: IdParam) => `picha_checkout_draft_${eventId}`;
const PROFILE_KEY = 'picha_buyer_profile';
const PENDING_CONTACT_KEY = 'picha_buyer_pending_contact';

export interface BuyerContact {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
}

export interface DraftAnswer {
    question_id?: number;
    answer: string | string[];
}

export interface DraftParticipant {
    line_key?: string;
    first_name: string;
    last_name: string;
    birth_date: string;
    questions: DraftAnswer[];
}

export interface DraftAwaitingPayment {
    shortId: string;
    reservedUntil?: string;
    sessionIdentifier?: string;
    signature: string;
}

export interface CheckoutDraft {
    stepId: string;
    selection: Record<string, number>;
    order: {
        first_name: string;
        last_name: string;
        email: string;
        opted_into_marketing: boolean;
        questions: DraftAnswer[];
    };
    participants: DraftParticipant[];
    protectionOptIn: boolean;
    awaitingPayment?: DraftAwaitingPayment | null;
}

interface StoredDraft extends CheckoutDraft {
    v: number;
    savedAt: number;
    expiresAt: number;
}

const storage = (): Storage | null => {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

const readJson = <T>(key: string): T | null => {
    try {
        const raw = storage()?.getItem(key);
        return raw ? JSON.parse(raw) as T : null;
    } catch {
        return null;
    }
};

const writeJson = (key: string, value: unknown) => {
    try {
        storage()?.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or blocked (private browsing, in-app browsers): the form keeps working without memory.
    }
};

const remove = (key: string) => {
    try {
        storage()?.removeItem(key);
    } catch {
        // ignore
    }
};

const isObject = (value: unknown): value is Record<string, any> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

const asString = (value: unknown, max = 200): string =>
    typeof value === 'string' ? value.slice(0, max) : '';

export const draftExpiry = (eventEnd?: string | null, now = Date.now()): number => {
    const sevenDays = now + DRAFT_TTL_MS;
    const end = eventEnd ? Date.parse(eventEnd) : NaN;
    return Number.isFinite(end) && end > now ? Math.min(sevenDays, end) : sevenDays;
};

export const readCheckoutDraft = (eventId: IdParam): CheckoutDraft | null => {
    const stored = readJson<StoredDraft>(draftKey(eventId));
    if (!isObject(stored) || stored.v !== DRAFT_VERSION || !isObject(stored.order)) {
        if (stored) {
            remove(draftKey(eventId));
        }
        return null;
    }
    if (typeof stored.expiresAt !== 'number' || stored.expiresAt <= Date.now()) {
        remove(draftKey(eventId));
        return null;
    }
    return {
        stepId: asString(stored.stepId, 80),
        selection: isObject(stored.selection) ? stored.selection : {},
        order: {
            first_name: asString(stored.order.first_name),
            last_name: asString(stored.order.last_name),
            email: asString(stored.order.email),
            opted_into_marketing: !!stored.order.opted_into_marketing,
            questions: Array.isArray(stored.order.questions) ? stored.order.questions : [],
        },
        participants: Array.isArray(stored.participants) ? stored.participants : [],
        protectionOptIn: !!stored.protectionOptIn,
        awaitingPayment: isObject(stored.awaitingPayment) && typeof stored.awaitingPayment.shortId === 'string'
            ? stored.awaitingPayment as DraftAwaitingPayment
            : null,
    };
};

export const writeCheckoutDraft = (eventId: IdParam, draft: CheckoutDraft, eventEnd?: string | null) => {
    const now = Date.now();
    writeJson(draftKey(eventId), {
        ...draft,
        v: DRAFT_VERSION,
        savedAt: now,
        expiresAt: draftExpiry(eventEnd, now),
    } satisfies StoredDraft);
};

export const clearCheckoutDraft = (eventId: IdParam) => remove(draftKey(eventId));

const normaliseContact = (value: unknown): BuyerContact | null => {
    if (!isObject(value)) {
        return null;
    }
    const contact = {
        first_name: asString(value.first_name, 80),
        last_name: asString(value.last_name, 80),
        email: asString(value.email, 200),
        phone: asString(value.phone, 40),
    };
    return contact.first_name || contact.last_name || contact.email || contact.phone ? contact : null;
};

export const readBuyerProfile = (): BuyerContact | null => {
    const stored = readJson<Record<string, any>>(PROFILE_KEY);
    if (!stored) {
        return null;
    }
    if (typeof stored.expiresAt !== 'number' || stored.expiresAt <= Date.now()) {
        remove(PROFILE_KEY);
        return null;
    }
    return normaliseContact(stored);
};

export const saveBuyerProfile = (contact: BuyerContact) => {
    const normalised = normaliseContact(contact);
    if (!normalised) {
        return;
    }
    const previous = readBuyerProfile();
    writeJson(PROFILE_KEY, {
        ...normalised,
        phone: normalised.phone || (previous?.email === normalised.email ? previous.phone : ''),
        expiresAt: Date.now() + PROFILE_TTL_MS,
    });
};

export const clearBuyerProfile = () => {
    remove(PROFILE_KEY);
    remove(PENDING_CONTACT_KEY);
};

/**
 * Remembers who is paying for an order on this device, so the contact is only kept once
 * that same order is confirmed here (never from an order link opened on another device).
 */
export const rememberPendingContact = (orderShortId: string, contact: BuyerContact) => {
    const normalised = normaliseContact(contact);
    if (!normalised || !orderShortId) {
        return;
    }
    writeJson(PENDING_CONTACT_KEY, {...normalised, shortId: orderShortId, expiresAt: Date.now() + PENDING_CONTACT_TTL_MS});
};

/**
 * Called on the order summary page: once the order is confirmed, keep the buyer's
 * contact for the next forms and drop the event draft.
 */
export const confirmOrderOnDevice = (eventId: IdParam, orderShortId: string) => {
    const pending = readJson<Record<string, any>>(PENDING_CONTACT_KEY);
    if (pending?.shortId === orderShortId) {
        if (typeof pending.expiresAt === 'number' && pending.expiresAt > Date.now()) {
            const contact = normaliseContact(pending);
            if (contact) {
                saveBuyerProfile(contact);
            }
        }
        remove(PENDING_CONTACT_KEY);
    }
    const draft = readCheckoutDraft(eventId);
    if (draft?.awaitingPayment?.shortId === orderShortId) {
        clearCheckoutDraft(eventId);
    }
};
