import {useEffect, useMemo, useState, useRef} from 'react';
import {TrackingPixelConfig} from '../types';
import {initializeTrackingPixels, trackPageView, cleanup} from '../utilites/trackingPixels';
import {CONSENT_CHANGE_EVENT, getConsentState, initGoogleConsentMode} from '../utilites/trackingPixels/consent';

interface UseOrganizerTrackingPixelsReturn {
    consentGranted: boolean;
}

export function useOrganizerTrackingPixels(
    trackingPixels: TrackingPixelConfig[] | undefined
): UseOrganizerTrackingPixelsReturn {
    const hasPixels = !!trackingPixels?.length;
    const pixelsKey = useMemo(
        () => JSON.stringify(trackingPixels ?? []),
        [trackingPixels]
    );

    // Avoid SSR hydration mismatch by defaulting to false and reading cookie on mount
    const [consentGranted, setConsentGranted] = useState(false);
    const consentModeInitialized = useRef(false);

    // On mount: read consent state and initialize Google Consent Mode synchronously
    useEffect(() => {
        const currentConsent = getConsentState() === 'granted';
        setConsentGranted(currentConsent);

        if (hasPixels && !consentModeInitialized.current) {
            initGoogleConsentMode(currentConsent);
            consentModeInitialized.current = true;
        }
    }, [hasPixels]);

    // Listen for consent changes from the consent banner
    useEffect(() => {
        if (typeof window === 'undefined') return;

        const handler = (e: Event) => {
            const granted = (e as CustomEvent).detail?.granted;
            setConsentGranted(granted === true);
        };

        window.addEventListener(CONSENT_CHANGE_EVENT, handler);
        return () => window.removeEventListener(CONSENT_CHANGE_EVENT, handler);
    }, []);

    // Initialize pixels when consent is granted
    useEffect(() => {
        if (!hasPixels || !consentGranted) return;

        initializeTrackingPixels(trackingPixels!);

        // Delay pageview to allow vendor scripts to load asynchronously
        const timer = setTimeout(() => {
            trackPageView();
        }, 100);

        return () => {
            clearTimeout(timer);
            cleanup();
        };
    }, [pixelsKey, consentGranted]);

    return {consentGranted};
}
