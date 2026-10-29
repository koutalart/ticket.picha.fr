import {RefObject, useEffect} from "react";

export const STICKY_CTA_HEIGHT_VAR = '--picha-sticky-cta-height';
export const STICKY_CTA_ATTR = 'data-picha-sticky-cta';

// A bar counts when it is pinned to the bottom of the screen: its bottom edge sits within this distance of it.
const PINNED_MARGIN = 80;
const SETTLE_MS = 600;

const heights = new Map<symbol, number>();

const publish = () => {
    const root = document.documentElement;
    const height = Math.max(0, ...heights.values());
    if (height > 0) {
        root.style.setProperty(STICKY_CTA_HEIGHT_VAR, `${height}px`);
        root.setAttribute(STICKY_CTA_ATTR, '');
    } else {
        root.style.setProperty(STICKY_CTA_HEIGHT_VAR, '0px');
        root.removeAttribute(STICKY_CTA_ATTR);
    }
};

type StickyTarget = RefObject<HTMLElement> | (() => HTMLElement | null | undefined);

/**
 * Publishes on <html> the space a sticky buy bar takes at the bottom of the screen (--picha-sticky-cta-height,
 * measured from the bottom of the screen to the top of the bar), so that floating buttons such as the chat bubble
 * sit above it instead of covering it. Back to 0 when the bar is hidden, scrolled away or unmounted.
 */
export const useStickyCtaHeight = (target: StickyTarget, active = true) => {
    useEffect(() => {
        if (!active || typeof window === 'undefined') {
            return;
        }
        const key = Symbol('sticky-cta');
        const resolve = () => (typeof target === 'function' ? target() : target.current) ?? null;
        let observed: HTMLElement | null = null;
        let frame = 0;

        const resizeObserver = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(() => schedule())
            : null;

        const measure = () => {
            frame = 0;
            const element = resolve();
            if (element !== observed) {
                if (observed) {
                    resizeObserver?.unobserve(observed);
                }
                if (element) {
                    resizeObserver?.observe(element);
                }
                observed = element;
            }
            const layoutHeight = document.documentElement.clientHeight || window.innerHeight;
            const rect = element?.getBoundingClientRect();
            const pinned = !!rect && rect.height > 0 && rect.top < layoutHeight && rect.bottom >= layoutHeight - PINNED_MARGIN;
            heights.set(key, pinned && rect ? Math.round(layoutHeight - rect.top) : 0);
            publish();
        };
        function schedule() {
            if (!frame) {
                frame = window.requestAnimationFrame(measure);
            }
        }

        measure();
        // Measured again once the page has settled, without waiting for a frame (paused in a background tab).
        const settleTimer = window.setTimeout(measure, SETTLE_MS);
        window.addEventListener('load', schedule);
        // The page around the bar changes too (images, steps of a form): a bar pinned by position: sticky moves then.
        resizeObserver?.observe(document.body);
        window.addEventListener('animationend', schedule, true);
        window.addEventListener('transitionend', schedule, true);
        window.addEventListener('scroll', schedule, {passive: true});
        window.addEventListener('resize', schedule);
        window.visualViewport?.addEventListener('resize', schedule);

        return () => {
            window.cancelAnimationFrame(frame);
            window.clearTimeout(settleTimer);
            window.removeEventListener('load', schedule);
            resizeObserver?.disconnect();
            window.removeEventListener('animationend', schedule, true);
            window.removeEventListener('transitionend', schedule, true);
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            window.visualViewport?.removeEventListener('resize', schedule);
            heights.delete(key);
            publish();
        };
    }, [active]);
};
