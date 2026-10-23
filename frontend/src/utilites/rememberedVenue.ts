import {Event, IdParam} from "../types.ts";

const STORAGE_KEY_PREFIX = 'picha_venue_';
const FALLBACK_LIFETIME_MS = 30 * 24 * 60 * 60 * 1000;

interface RegisteredOnDeviceEntry {
    registered: true;
    expiresAt: number;
}

const storageKey = (eventId: IdParam) => `${STORAGE_KEY_PREFIX}${eventId}`;

const getStorage = (): Storage | null => {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

const computeExpiry = (event: Pick<Event, 'start_date' | 'end_date'>): number => {
    const endTime = Date.parse(event.end_date || event.start_date || '');
    return Number.isFinite(endTime) ? endTime : Date.now() + FALLBACK_LIFETIME_MS;
};

export const markRegisteredOnDevice = (event: Pick<Event, 'id' | 'start_date' | 'end_date'>) => {
    const storage = getStorage();
    if (!storage || !event?.id) {
        return;
    }
    const expiresAt = computeExpiry(event);
    if (expiresAt < Date.now()) {
        return;
    }
    try {
        const entry: RegisteredOnDeviceEntry = {registered: true, expiresAt};
        storage.setItem(storageKey(event.id), JSON.stringify(entry));
    } catch {
        // localStorage unavailable
    }
};

export const forgetRegisteredOnDevice = (eventId: IdParam) => {
    const storage = getStorage();
    if (!storage || !eventId) {
        return;
    }
    try {
        storage.removeItem(storageKey(eventId));
    } catch {
        // localStorage unavailable
    }
};

export const isRegisteredOnDevice = (eventId: IdParam): boolean => {
    const storage = getStorage();
    if (!storage || !eventId) {
        return false;
    }
    try {
        const raw = storage.getItem(storageKey(eventId));
        if (!raw) {
            return false;
        }
        const entry = JSON.parse(raw) as Partial<RegisteredOnDeviceEntry>;
        if (entry?.registered !== true || typeof entry.expiresAt !== 'number' || entry.expiresAt < Date.now()) {
            storage.removeItem(storageKey(eventId));
            return false;
        }
        return true;
    } catch {
        forgetRegisteredOnDevice(eventId);
        return false;
    }
};

export const venuePath = (eventId: IdParam, attendeeShortId: string, code: string) =>
    `/venue/${eventId}/${attendeeShortId}?code=${encodeURIComponent(code)}`;
