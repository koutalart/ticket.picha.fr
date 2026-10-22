import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {i18n} from "@lingui/core";
import {IconTicket} from "@tabler/icons-react";
import {useReducedMotion} from "@mantine/hooks";
import {useGetServerClockOffset} from "../../../../queries/useGetServerClockOffset.ts";
import classes from "./SaleCountdown.module.scss";

interface SaleCountdownProps {
    opensAt: Date;
    timezone?: string;
    onReached: () => void;
}

const pad = (value: number) => String(value).padStart(2, '0');

const formatOpening = (opensAt: Date, timezone?: string) => {
    const locale = i18n.locale || 'fr';
    const options = {timeZone: timezone || undefined};
    const day = opensAt.toLocaleDateString(locale, {...options, weekday: 'long', day: 'numeric', month: 'long'});
    const parts = new Intl.DateTimeFormat(locale, {...options, hour: '2-digit', minute: '2-digit', hourCycle: 'h23'})
        .formatToParts(opensAt);
    const hours = parts.find(part => part.type === 'hour')?.value ?? '00';
    const minutes = parts.find(part => part.type === 'minute')?.value ?? '00';
    const time = locale.startsWith('fr') ? `${hours}h${minutes}` : `${hours}:${minutes}`;

    return {day, time};
};

export const SaleCountdown = ({opensAt, timezone, onReached}: SaleCountdownProps) => {
    const {offset, isSettled} = useGetServerClockOffset();
    const reduceMotion = useReducedMotion();
    const [now, setNow] = useState<number | null>(null);
    const reachedRef = useRef(false);
    const onReachedRef = useRef(onReached);
    onReachedRef.current = onReached;

    useEffect(() => {
        const tick = () => setNow(Date.now() + offset);
        tick();
        const interval = window.setInterval(tick, 1000);
        return () => window.clearInterval(interval);
    }, [offset]);

    const remaining = now === null ? null : Math.max(0, opensAt.getTime() - now);

    useEffect(() => {
        if (remaining === 0 && isSettled && !reachedRef.current) {
            reachedRef.current = true;
            onReachedRef.current();
        }
    }, [remaining, isSettled]);

    const totalSeconds = remaining === null ? null : Math.ceil(remaining / 1000);
    const units = [
        {label: t`days`, value: totalSeconds === null ? null : Math.floor(totalSeconds / 86400)},
        {label: t`hours`, value: totalSeconds === null ? null : Math.floor((totalSeconds % 86400) / 3600)},
        {label: t`minutes`, value: totalSeconds === null ? null : Math.floor((totalSeconds % 3600) / 60)},
        {label: t`seconds`, value: totalSeconds === null ? null : totalSeconds % 60},
    ];
    const {day, time} = formatOpening(opensAt, timezone);

    return (
        <div className={classes.countdown} data-reduce-motion={reduceMotion ? 'true' : undefined}>
            <span className={classes.eyebrow}>
                <IconTicket size={18} aria-hidden="true"/>
                {t`Free registration`}
            </span>
            <div className={classes.units} role="timer" aria-live="off" aria-label={t`Time before registration opens`}>
                {units.map((unit, index) => (
                    <div className={classes.unit} key={unit.label}>
                        <span
                            className={classes.value}
                            key={index === units.length - 1 && !reduceMotion ? String(unit.value) : undefined}
                            data-tick={index === units.length - 1 ? 'true' : undefined}
                        >
                            {unit.value === null ? '--' : pad(unit.value)}
                        </span>
                        <span className={classes.label}>{unit.label}</span>
                    </div>
                ))}
            </div>
            <h2 className={classes.title}>{t`Free registration on ${day} at ${time}`}</h2>
            {remaining === 0 && (
                <p className={classes.opening} role="status">{t`Registration is opening...`}</p>
            )}
        </div>
    );
};
