import { useEffect, useState } from "react";
import classes from "./Countdown.module.scss";

interface CountdownProps {
    targetDate: string;
}

interface TimeLeft {
    days: number;
    hours: number;
    minutes: number;
    seconds: number;
}

const computeTimeLeft = (target: Date): TimeLeft => {
    const diff = Math.max(0, target.getTime() - Date.now());
    return {
        days: Math.floor(diff / 86400000),
        hours: Math.floor((diff % 86400000) / 3600000),
        minutes: Math.floor((diff % 3600000) / 60000),
        seconds: Math.floor((diff % 60000) / 1000),
    };
};

export const Countdown = ({ targetDate }: CountdownProps) => {
    const target = new Date(targetDate);
    const [timeLeft, setTimeLeft] = useState<TimeLeft>(() => computeTimeLeft(target));

    useEffect(() => {
        const interval = setInterval(() => {
            setTimeLeft(computeTimeLeft(target));
        }, 1000);
        return () => clearInterval(interval);
    }, [targetDate]);

    const pad = (n: number) => String(n).padStart(2, "0");

    return (
        <div className={classes.section}>
            <div className={classes.label}>Le compte à rebours a commencé</div>
            <div className={classes.grid}>
                <div className={classes.item}>
                    <div className={classes.num}>{pad(timeLeft.days)}</div>
                    <div className={classes.unit}>Jours</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.num}>{pad(timeLeft.hours)}</div>
                    <div className={classes.unit}>Heures</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.num}>{pad(timeLeft.minutes)}</div>
                    <div className={classes.unit}>Minutes</div>
                </div>
                <div className={classes.item}>
                    <div className={classes.num}>{pad(timeLeft.seconds)}</div>
                    <div className={classes.unit}>Secondes</div>
                </div>
            </div>
        </div>
    );
};
