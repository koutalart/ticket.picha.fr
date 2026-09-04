const STORAGE_KEY = 'checkout_session_identifier';

export const getStoredSessionIdentifier = (): string | null => {
    if (typeof window === 'undefined') return null;
    try {
        return window.sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
};

export const storeSessionIdentifier = (identifier: string): void => {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.setItem(STORAGE_KEY, identifier);
    } catch {
        // sessionStorage unavailable (e.g. some private modes) - fail silently
    }
};
