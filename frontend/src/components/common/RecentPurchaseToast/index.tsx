import {RefObject, useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconTicket, IconX} from "@tabler/icons-react";
import {IdParam, RecentPurchase} from "../../../types.ts";
import {useGetRecentPurchases} from "../../../queries/useGetRecentPurchases.ts";
import classes from "./RecentPurchaseToast.module.scss";

const FIRST_DELAY_MS = 8000;
const INTERVAL_MS = 25000;
const VISIBLE_MS = 5000;
const MAX_TOASTS = 4;
const TICK_MS = 2000;
const HERO_COVER_RATIO = 0.6;

interface RecentPurchaseToastProps {
    eventId?: IdParam;
    enabled: boolean;
    sectionRefs?: RefObject<HTMLElement | null>[];
    heroRef?: RefObject<HTMLElement | null>;
    aboveBuyBar?: boolean;
}

const isTypingTarget = (element: Element | null): boolean =>
    !!element && (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA' || element.tagName === 'SELECT'
        || (element as HTMLElement).isContentEditable);

const isModalOpen = (): boolean =>
    !!document.querySelector('[role="dialog"][aria-modal="true"], .mantine-Modal-root, .mantine-Drawer-root');

const describe = (purchase: RecentPurchase, elapsedMinutes: number) => {
    const quantity = purchase.quantity;
    const minutes = purchase.minutes_ago + elapsedMinutes;
    const isRecent = minutes < 60;

    const title = isRecent
        ? (quantity === 1 ? t`Someone just took 1 place` : t`Someone just took ${quantity} places`)
        : (quantity === 1 ? t`Someone took 1 place` : t`Someone took ${quantity} places`);

    let when: string;
    if (minutes < 1) {
        when = t`just now`;
    } else if (isRecent) {
        when = t`${minutes} min ago`;
    } else {
        const hours = Math.floor(minutes / 60);
        when = t`${hours} h ago`;
    }

    return {title, when};
};

/**
 * Discreet, anonymous social proof built from real recent orders (quantity and age only).
 */
export const RecentPurchaseToast = ({eventId, enabled, sectionRefs, heroRef, aboveBuyBar}: RecentPurchaseToastProps) => {
    const [mounted, setMounted] = useState(false);
    const [current, setCurrent] = useState<{ title: string, when: string } | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const shownCount = useRef(0);
    const typing = useRef(false);
    const heroCovers = useRef(false);
    const [atTop, setAtTop] = useState(false);

    useEffect(() => setMounted(true), []);

    const {data: purchases, dataUpdatedAt} = useGetRecentPurchases(eventId, mounted && enabled && !dismissed);

    useEffect(() => {
        if (!mounted) {
            return;
        }
        const onFocusIn = (event: FocusEvent) => {
            typing.current = isTypingTarget(event.target as Element);
            if (typing.current) {
                setCurrent(null);
            }
        };
        const onFocusOut = () => {
            typing.current = false;
        };
        document.addEventListener('focusin', onFocusIn);
        document.addEventListener('focusout', onFocusOut);
        return () => {
            document.removeEventListener('focusin', onFocusIn);
            document.removeEventListener('focusout', onFocusOut);
        };
    }, [mounted]);

    useEffect(() => {
        const sections = (sectionRefs ?? []).map(ref => ref.current).filter((el): el is HTMLElement => !!el);
        const hero = heroRef?.current;
        if (!mounted || typeof IntersectionObserver === 'undefined') {
            return;
        }
        const visibleSections = new Set<Element>();
        const sectionObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => entry.isIntersecting ? visibleSections.add(entry.target) : visibleSections.delete(entry.target));
            setAtTop(visibleSections.size > 0);
        });
        sections.forEach(section => sectionObserver.observe(section));

        const heroObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                heroCovers.current = entry.isIntersecting
                    && entry.intersectionRect.height >= window.innerHeight * HERO_COVER_RATIO;
                if (heroCovers.current) {
                    setCurrent(null);
                }
            });
        }, {threshold: [0, 0.2, 0.4, 0.6, 0.8, 1]});
        if (hero) {
            heroObserver.observe(hero);
        }

        return () => {
            sectionObserver.disconnect();
            heroObserver.disconnect();
        };
    }, [mounted, sectionRefs, heroRef]);

    useEffect(() => {
        if (!mounted || !enabled || dismissed || !purchases || purchases.length === 0) {
            return;
        }

        let hideTimer: ReturnType<typeof setTimeout> | undefined;
        let index = shownCount.current % purchases.length;
        let nextAt = Date.now() + FIRST_DELAY_MS;

        const tick = () => {
            if (shownCount.current >= MAX_TOASTS) {
                clearInterval(interval);
                return;
            }
            if (Date.now() < nextAt || document.visibilityState === 'hidden' || typing.current
                || heroCovers.current || isTypingTarget(document.activeElement) || isModalOpen()) {
                return;
            }

            const elapsedMinutes = Math.max(0, Math.floor((Date.now() - dataUpdatedAt) / 60000));
            setCurrent(describe(purchases[index], elapsedMinutes));
            index = (index + 1) % purchases.length;
            shownCount.current += 1;
            nextAt = Date.now() + INTERVAL_MS;
            hideTimer = setTimeout(() => setCurrent(null), VISIBLE_MS);
        };

        const interval = setInterval(tick, TICK_MS);

        return () => {
            clearInterval(interval);
            if (hideTimer) {
                clearTimeout(hideTimer);
            }
        };
    }, [mounted, enabled, dismissed, purchases, dataUpdatedAt]);

    if (!mounted || !current || dismissed || !enabled) {
        return null;
    }

    return (
        <div
            className={`${classes.toast} ${atTop ? classes.top : (aboveBuyBar ? classes.aboveBuyBar : '')}`}
            role="status"
            aria-live="polite"
        >
            <span className={classes.icon} aria-hidden="true"><IconTicket size={16}/></span>
            <span className={classes.text}>
                <span className={classes.title}>{current.title}</span>
                <span className={classes.when}>{current.when}</span>
            </span>
            <button
                type="button"
                className={classes.close}
                onClick={() => {
                    setDismissed(true);
                    setCurrent(null);
                }}
                aria-label={t`Close`}
            >
                <IconX size={14} aria-hidden="true"/>
            </button>
        </div>
    );
};
