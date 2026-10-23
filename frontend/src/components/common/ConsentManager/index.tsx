import {useCallback, useEffect, useRef, useState, useSyncExternalStore} from "react";
import {CookieConsentBanner, CookieConsentSelection, CookieConsentView} from "../CookieConsentBanner";
import {
    CONSENT_CHANGE_EVENT,
    CONSENT_OPEN_EVENT,
    getConsentChoice,
    isConsentPending,
    updateGoogleConsentMode,
    writeConsentChoice,
} from "../../../utilites/trackingPixels/consent.ts";
import {applyConsentToAttribution} from "../../../utilites/attribution";
import {getConsentBannerTheme, subscribeConsentBannerTheme} from "../../../utilites/consentBannerTheme.ts";

/**
 * Back office, previews, embedded widget, kiosk and print pages never show the banner.
 */
const NO_BANNER_PATH = /^\/(manage|admin|auth|account|welcome|kiosk|profile|widget|check-in|digit)(\/|$)|^\/(event|organizer)\/\d+\/preview(\/|$)|\/print\/?$/;

export const isConsentBannerPath = (pathname: string): boolean => !NO_BANNER_PATH.test(pathname);

/**
 * Single consent banner of the app, shown on the first visit of every public page (platform host and
 * organizer custom domains alike) and reopened by "Manage my trackers".
 */
export const ConsentManager = () => {
    const theme = useSyncExternalStore(subscribeConsentBannerTheme, getConsentBannerTheme, () => null);
    const [view, setView] = useState<CookieConsentView | null>(null);
    const answered = useRef(false);

    useEffect(() => {
        if (isConsentPending() && isConsentBannerPath(window.location.pathname)) {
            setView('main');
        }

        const open = () => setView('preferences');
        window.addEventListener(CONSENT_OPEN_EVENT, open);
        return () => window.removeEventListener(CONSENT_OPEN_EVENT, open);
    }, []);

    useEffect(() => {
        if (theme && !answered.current && isConsentPending() && isConsentBannerPath(window.location.pathname)) {
            setView(current => current ?? 'main');
        }
    }, [theme]);

    const save = useCallback((selection: CookieConsentSelection) => {
        const choice = writeConsentChoice(selection);
        answered.current = true;
        updateGoogleConsentMode(choice.marketing);
        applyConsentToAttribution(choice);
        window.dispatchEvent(new CustomEvent(CONSENT_CHANGE_EVENT, {detail: {granted: choice.marketing, choice}}));
        setView(null);
    }, []);

    if (!view) {
        return null;
    }

    return (
        <CookieConsentBanner
            key={view}
            view={view}
            theme={theme}
            initialSelection={getConsentChoice()}
            onSave={save}
            onCustomize={() => setView('preferences')}
        />
    );
};

export default ConsentManager;
