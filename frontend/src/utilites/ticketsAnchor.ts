export const TICKETS_SECTION_ID = 'tickets';
export const BUY_QUERY_PARAM = 'acheter';

const ANCHOR_GAP = 12;
const SETTLE_MS = 700;
const MAX_WAIT_MS = 8000;
const TICK_MS = 80;
const USER_INTENT_EVENTS = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const;

/**
 * "#tickets" or "?acheter=1": the visitor came to buy (ad, SMS, QR code) and lands on the ticket form.
 */
export const wantsTicketsOnArrival = (location: Pick<Location, 'hash' | 'search'>): boolean => {
    if (location.hash === `#${TICKETS_SECTION_ID}`) {
        return true;
    }
    const value = new URLSearchParams(location.search).get(BUY_QUERY_PARAM);
    return value !== null && value !== '0' && value !== 'false';
};

/**
 * Height of a fixed or sticky bar pinned to the top of the screen (admin bar, organizer header),
 * ignoring full screen layers such as backgrounds and heroes.
 */
export const fixedTopOffset = (): number => {
    if (typeof document === 'undefined' || !document.elementsFromPoint) {
        return 0;
    }
    let offset = 0;
    const seen = new Set<Element>();
    document.elementsFromPoint(window.innerWidth / 2, 2).forEach((element) => {
        for (let node: Element | null = element; node && node !== document.body; node = node.parentElement) {
            if (seen.has(node)) {
                break;
            }
            seen.add(node);
            const position = window.getComputedStyle(node).position;
            if (position !== 'fixed' && position !== 'sticky') {
                continue;
            }
            const rect = node.getBoundingClientRect();
            if (rect.top <= 2 && rect.height > 0 && rect.height < window.innerHeight * 0.4) {
                offset = Math.max(offset, rect.bottom);
            }
        }
    });
    return offset;
};

const ticketsTop = (target: HTMLElement): number =>
    Math.max(0, Math.round(target.getBoundingClientRect().top + window.scrollY - fixedTopOffset() - ANCHOR_GAP));

export const scrollToTicketsSection = (target: HTMLElement | null, smooth = true) => {
    if (!target) {
        return;
    }
    const reduceMotion = !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top: ticketsTop(target), behavior: smooth && !reduceMotion ? 'smooth' : 'instant' as ScrollBehavior});
};

/**
 * The browser jumps to the anchor once, while the page is still under the loader, before the ticket form has loaded
 * and the images above it have their height. This keeps the form aligned until the layout settles, and lets go
 * as soon as the visitor really touches, scrolls or types (trusted events only).
 *
 * Driven by timers rather than requestAnimationFrame: in-app browsers and embedded panes may not paint frames
 * while the page is loading, and a rAF loop then never runs.
 */
export const alignTicketsOnArrival = (): (() => void) => {
    if (typeof window === 'undefined' || !wantsTicketsOnArrival(window.location)) {
        return () => undefined;
    }

    let timer: number | undefined;
    let stopped = false;
    let lastTop = Number.NaN;
    let stableSince = 0;
    let startedAt = performance.now();

    const cleanUp = () => {
        stopped = true;
        window.clearTimeout(timer);
        USER_INTENT_EVENTS.forEach((type) => window.removeEventListener(type, onUserIntent, true));
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('load', onLoad);
    };

    const onUserIntent = (event: Event) => {
        if (event.isTrusted) {
            cleanUp();
        }
    };

    const tick = () => {
        if (stopped) {
            return;
        }
        const now = performance.now();
        const target = document.getElementById(TICKETS_SECTION_ID);
        if (target && target.getAttribute('aria-busy') !== 'true') {
            const top = ticketsTop(target);
            if (Math.abs(window.scrollY - top) > 2) {
                window.scrollTo({top, behavior: 'instant' as ScrollBehavior});
            }
            if (Number.isNaN(lastTop) || Math.abs(top - lastTop) > 2) {
                lastTop = top;
                stableSince = now;
            } else if (now - stableSince > SETTLE_MS && document.readyState === 'complete') {
                cleanUp();
                return;
            }
        }
        if (now - startedAt > MAX_WAIT_MS && !document.hidden) {
            cleanUp();
            return;
        }
        timer = window.setTimeout(tick, TICK_MS);
    };

    const restart = () => {
        window.clearTimeout(timer);
        tick();
    };

    function onVisibilityChange() {
        if (!document.hidden) {
            startedAt = performance.now();
            restart();
        }
    }

    function onLoad() {
        restart();
    }

    USER_INTENT_EVENTS.forEach((type) => window.addEventListener(type, onUserIntent, {capture: true, passive: true}));
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('load', onLoad);
    timer = window.setTimeout(tick, 0);

    return cleanUp;
};
