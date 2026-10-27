export const TICKETS_SECTION_ID = 'tickets';
export const BUY_QUERY_PARAM = 'acheter';

const ANCHOR_GAP = 12;
const SETTLE_MS = 700;
const MAX_WAIT_MS = 8000;
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
 * as soon as the visitor touches the page.
 */
export const alignTicketsOnArrival = (): (() => void) => {
    if (typeof window === 'undefined' || !wantsTicketsOnArrival(window.location)) {
        return () => undefined;
    }

    let frame = 0;
    let stopped = false;
    let lastTop = Number.NaN;
    let stableSince = 0;
    const startedAt = performance.now();

    const stop = () => {
        if (stopped) {
            return;
        }
        stopped = true;
        window.cancelAnimationFrame(frame);
        USER_INTENT_EVENTS.forEach((type) => window.removeEventListener(type, stop, true));
    };

    const tick = (now: number) => {
        if (stopped) {
            return;
        }
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
                stop();
                return;
            }
        }
        if (now - startedAt > MAX_WAIT_MS) {
            stop();
            return;
        }
        frame = window.requestAnimationFrame(tick);
    };

    USER_INTENT_EVENTS.forEach((type) => window.addEventListener(type, stop, {capture: true, passive: true}));
    frame = window.requestAnimationFrame(tick);

    return stop;
};
