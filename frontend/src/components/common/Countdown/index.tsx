import {useEffect, useRef, useState} from 'react';
import {t} from '@lingui/macro';
import classNames from "classnames";
import {parseUtcTimestamp} from "../../../utilites/dates.ts";

interface CountdownProps {
    targetDate: string;
    onExpiry?: () => void;
    className?: string;
    closeToExpiryClassName?: string;
    displayType?: 'short' | 'long';
}

const formatTimeLeft = (diff: number, displayType: 'short' | 'long'): string => {
    if (diff <= 0) {
        return displayType === 'short' ? '0:00' : t`0 minutes and 0 seconds`;
    }

    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
    const minutes = Math.floor((diff / 1000 / 60) % 60);
    const seconds = Math.floor((diff / 1000) % 60);

    if (displayType === 'short') {
        const totalHours = days * 24 + hours;
        const formattedSeconds = String(seconds).padStart(2, '0');

        if (totalHours > 0) {
            return `${totalHours}:${String(minutes).padStart(2, '0')}:${formattedSeconds}`;
        }

        return `${minutes}:${formattedSeconds}`;
    }

    if (days > 0) {
        return t`${days} days, ${hours} hours, ${minutes} minutes, and ${seconds} seconds`;
    }
    if (hours > 0) {
        return t`${hours} hours, ${minutes} minutes, and ${seconds} seconds`;
    }
    return t`${minutes} minutes and ${seconds} seconds`;
};

export const Countdown = ({
                              targetDate,
                              onExpiry,
                              className = '',
                              displayType = 'long',
                              closeToExpiryClassName = ''
                          }: CountdownProps) => {
    const [timeLeft, setTimeLeft] = useState<string | null>(null);
    const [closeToExpiry, setCloseToExpiry] = useState(false);
    const onExpiryRef = useRef(onExpiry);
    onExpiryRef.current = onExpiry;

    useEffect(() => {
        const target = parseUtcTimestamp(targetDate);

        if (target === null) {
            setTimeLeft(null);
            return;
        }

        let interval: ReturnType<typeof setInterval> | undefined;

        const tick = (): boolean => {
            const diff = target - Date.now();
            setTimeLeft(formatTimeLeft(diff, displayType));
            setCloseToExpiry(diff > 0 && diff < 5 * 60 * 1000);

            if (diff <= 0) {
                if (interval) {
                    clearInterval(interval);
                }
                onExpiryRef.current?.();
                return false;
            }

            return true;
        };

        if (tick()) {
            interval = setInterval(tick, 1000);
        }

        return () => {
            if (interval) {
                clearInterval(interval);
            }
        };
    }, [targetDate, displayType]);

    if (timeLeft === null) {
        return null;
    }

    return (
        <span className={classNames(className, closeToExpiry ? closeToExpiryClassName : '')}>
             {timeLeft}
        </span>
    );
};
