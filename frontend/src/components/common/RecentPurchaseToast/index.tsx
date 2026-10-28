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

interface RecentPurchaseToastProps {
    eventId?: IdParam;
    enabled: boolean;
    avoidRefs?: RefObject<HTMLElement | null>[];
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
export const RecentPurchaseToast = ({eventId, enabled, avoidRefs, aboveBuyBar}: RecentPurchaseToastProps) => {
    const [mounted, setMounted] = useState(false);
    const [current, setCurrent] = useState<{ title: string, when: string } | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const shownCount = useRef(0);
    const typing = useRef(false);
    const avoidInView = useRef(false);

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
        const targets = (avoidRefs ?? []).map(ref => ref.current).filter((el): el is HTMLElement => !!el);
        if (!mounted || targets.length === 0 || typeof IntersectionObserver === 'undefined') {
            return;
        }
        const visible = new Set<Element>();
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => entry.isIntersecting ? visible.add(entry.target) : visible.delete(entry.target));
            avoidInView.current = visible.size > 0;
            if (avoidInView.current) {
                setCurrent(null);
            }
        }, {threshold: 0.05});
        targets.forEach(target => observer.observe(target));
        return () => observer.disconnect();
    }, [mounted, avoidRefs]);

    useEffect(() => {
        if (!mounted || !enabled || dismissed || !purchases || purchases.length === 0) {
            return;
        }

        let hideTimer: ReturnType<typeof setTimeout> | undefined;
        let index = shownCount.current % purchases.length;

        const tryShow = () => {
            if (shownCount.current >= MAX_TOASTS) {
                return false;
            }
            if (document.visibilityState === 'hidden' || typing.current || avoidInView.current
                || isTypingTarget(document.activeElement) || isModalOpen()) {
                return true;
            }

            const elapsedMinutes = Math.max(0, Math.floor((Date.now() - dataUpdatedAt) / 60000));
            setCurrent(describe(purchases[index], elapsedMinutes));
            index = (index + 1) % purchases.length;
            shownCount.current += 1;
            hideTimer = setTimeout(() => setCurrent(null), VISIBLE_MS);
            return true;
        };

        let interval: ReturnType<typeof setInterval> | undefined;
        const firstTimer = setTimeout(() => {
            if (!tryShow()) {
                return;
            }
            interval = setInterval(() => {
                if (!tryShow() && interval) {
                    clearInterval(interval);
                }
            }, INTERVAL_MS);
        }, FIRST_DELAY_MS);

        return () => {
            clearTimeout(firstTimer);
            if (interval) {
                clearInterval(interval);
            }
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
            className={`${classes.toast} ${aboveBuyBar ? classes.aboveBuyBar : ''}`}
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
