export const KIOSK_SENTINEL_EMAIL_DOMAIN = 'no-mail.picha.invalid';

export const isKioskSentinelEmail = (email: string | null | undefined): boolean => {
    if (!email) {
        return true;
    }

    return email.toLowerCase().endsWith(`@${KIOSK_SENTINEL_EMAIL_DOMAIN}`);
};

export const displayAttendeeEmail = (email: string | null | undefined): string | null => {
    return isKioskSentinelEmail(email) ? null : email ?? null;
};
