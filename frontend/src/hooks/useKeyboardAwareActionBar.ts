import {RefObject, useEffect} from "react";

const MOBILE_QUERY = '(max-width: 600px)';
const FIELD_SELECTOR = 'input, textarea, select, [contenteditable="true"]';

const ACTION_BAR_OFFSET_VAR = '--picha-action-bar-offset';
const ACTION_BAR_VISIBLE_ATTR = 'data-action-bar-visible';

/**
 * Keeps a bottom action bar above the on-screen keyboard (visualViewport) on phones,
 * scrolls the focused field so the bar never hides it, and publishes the space the bar
 * takes at the bottom of the screen (--picha-action-bar-offset on <html>) so fixed
 * bottom elements such as the cookie bar sit above it instead of covering it.
 */
export const useKeyboardAwareActionBar = (
    containerRef: RefObject<HTMLElement>,
    barSelector: string,
    enabled = true,
) => {
    useEffect(() => {
        if (!enabled || typeof window === 'undefined') {
            return;
        }
        const container = containerRef.current;
        const viewport = window.visualViewport;
        if (!container) {
            return;
        }
        const isMobile = () => !!window.matchMedia?.(MOBILE_QUERY).matches;

        const updateOffset = () => {
            let offset = 0;
            if (viewport && isMobile() && viewport.scale < 1.05) {
                const layoutHeight = document.documentElement.clientHeight || window.innerHeight;
                offset = Math.max(0, Math.round(layoutHeight - viewport.height - viewport.offsetTop));
                if (offset < 60) {
                    offset = 0;
                }
            }
            container.style.setProperty('--er-keyboard-offset', `${offset}px`);
        };

        const root = document.documentElement;
        const activeBar = () => Array.from(container.querySelectorAll<HTMLElement>(barSelector))
            .find(candidate => !candidate.closest('[inert]'));

        let frame = 0;
        const publishBarOffset = () => {
            frame = 0;
            const bar = isMobile() ? activeBar() : undefined;
            const layoutHeight = root.clientHeight || window.innerHeight;
            const rect = bar?.getBoundingClientRect();
            const bottomBarHeight = parseFloat(getComputedStyle(root).getPropertyValue('--picha-cookie-bar-height')) || 0;
            const covers = !!rect && bottomBarHeight > 0 && rect.height > 0 && rect.top < layoutHeight
                && rect.bottom > layoutHeight - bottomBarHeight - 8
                && rect.top > layoutHeight * 0.35;
            if (covers && rect) {
                root.style.setProperty(ACTION_BAR_OFFSET_VAR, `${Math.max(0, Math.round(layoutHeight - rect.top))}px`);
                root.setAttribute(ACTION_BAR_VISIBLE_ATTR, '');
            } else {
                root.style.removeProperty(ACTION_BAR_OFFSET_VAR);
                root.removeAttribute(ACTION_BAR_VISIBLE_ATTR);
            }
        };
        const scheduleBarOffset = () => {
            if (!frame) {
                frame = window.requestAnimationFrame(publishBarOffset);
            }
        };

        let revealTimer: number | undefined;
        const revealField = (field: HTMLElement) => {
            if (!isMobile()) {
                return;
            }
            const bar = activeBar();
            if (!bar) {
                return;
            }
            const barTop = bar.getBoundingClientRect().top;
            const fieldRect = field.getBoundingClientRect();
            const visibleTop = viewport ? viewport.offsetTop : 0;
            const margin = 12;
            if (fieldRect.bottom > barTop - margin) {
                window.scrollBy({top: fieldRect.bottom - barTop + margin, behavior: 'smooth'});
            } else if (fieldRect.top < visibleTop + margin) {
                window.scrollBy({top: fieldRect.top - visibleTop - margin, behavior: 'smooth'});
            }
        };

        const handleFocusIn = (focusEvent: FocusEvent) => {
            const target = focusEvent.target as HTMLElement | null;
            if (!target?.matches?.(FIELD_SELECTOR)) {
                return;
            }
            window.clearTimeout(revealTimer);
            revealTimer = window.setTimeout(() => {
                updateOffset();
                if (document.activeElement === target) {
                    revealField(target);
                }
            }, 320);
        };

        const handleViewportChange = () => {
            updateOffset();
            scheduleBarOffset();
        };
        const resizeObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(scheduleBarOffset) : null;
        resizeObserver?.observe(container);

        handleViewportChange();
        viewport?.addEventListener('resize', handleViewportChange);
        viewport?.addEventListener('scroll', handleViewportChange);
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('scroll', scheduleBarOffset, {passive: true});
        window.addEventListener('picha:bottom-bar-change', scheduleBarOffset);
        container.addEventListener('focusin', handleFocusIn);
        container.addEventListener('animationend', scheduleBarOffset);

        return () => {
            window.clearTimeout(revealTimer);
            window.cancelAnimationFrame(frame);
            resizeObserver?.disconnect();
            window.removeEventListener('scroll', scheduleBarOffset);
            window.removeEventListener('picha:bottom-bar-change', scheduleBarOffset);
            container.removeEventListener('animationend', scheduleBarOffset);
            root.style.removeProperty(ACTION_BAR_OFFSET_VAR);
            root.removeAttribute(ACTION_BAR_VISIBLE_ATTR);
            viewport?.removeEventListener('resize', handleViewportChange);
            viewport?.removeEventListener('scroll', handleViewportChange);
            window.removeEventListener('resize', handleViewportChange);
            container.removeEventListener('focusin', handleFocusIn);
            container.style.removeProperty('--er-keyboard-offset');
        };
    }, [containerRef, barSelector, enabled]);
};
