import {useEffect, useState} from "react";

const SLOW_CONNECTIONS = ['slow-2g', '2g', '3g'];

/**
 * The cover video only starts once the page has finished loading, so the poster and the
 * text show first. It is never loaded when the visitor saves data or is on a slow network.
 */
export const useDeferredCoverVideo = (videoUrl?: string | null): boolean => {
    const [allowed, setAllowed] = useState(false);

    useEffect(() => {
        if (!videoUrl) {
            return;
        }

        const connection = (navigator as Navigator & {
            connection?: { saveData?: boolean; effectiveType?: string };
        }).connection;
        if (connection?.saveData || SLOW_CONNECTIONS.includes(connection?.effectiveType ?? '')) {
            return;
        }

        const start = () => setAllowed(true);
        if (document.readyState === 'complete') {
            const timer = window.setTimeout(start, 0);
            return () => window.clearTimeout(timer);
        }

        window.addEventListener('load', start, {once: true});
        return () => window.removeEventListener('load', start);
    }, [videoUrl]);

    return allowed;
};
