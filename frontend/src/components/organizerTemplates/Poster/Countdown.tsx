import {useEffect, useState} from "react";
import {t} from "@lingui/macro";
import classes from "./Poster.module.scss";

const pad = (value: number) => String(Math.max(0, value)).padStart(2, '0');

const getRemaining = (target: number) => {
    const diff = Math.max(0, target - Date.now());
    return {
        days: Math.floor(diff / 86_400_000),
        hours: Math.floor(diff / 3_600_000) % 24,
        minutes: Math.floor(diff / 60_000) % 60,
        seconds: Math.floor(diff / 1000) % 60,
    };
};

export const Countdown = ({startDate}: { startDate: string }) => {
    const target = new Date(startDate.endsWith('Z') ? startDate : `${startDate.replace(' ', 'T')}Z`).getTime();
    const [remaining, setRemaining] = useState<ReturnType<typeof getRemaining> | null>(null);

    useEffect(() => {
        setRemaining(getRemaining(target));
        const timer = window.setInterval(() => setRemaining(getRemaining(target)), 1000);
        return () => window.clearInterval(timer);
    }, [target]);

    if (target <= Date.now() && remaining) {
        return null;
    }

    const units = [
        {value: remaining?.days, label: t`days`},
        {value: remaining?.hours, label: t`hours`},
        {value: remaining?.minutes, label: t`min`},
        {value: remaining?.seconds, label: t`sec`},
    ];

    return (
        <div className={classes.countdown} role="timer" aria-label={t`Time left before the event`}>
            {units.map(({value, label}) => (
                <div key={label} className={classes.countdownUnit}>
                    <span className={classes.countdownValue}>{value === undefined ? '--' : pad(value)}</span>
                    <span className={classes.countdownLabel}>{label}</span>
                </div>
            ))}
        </div>
    );
};
