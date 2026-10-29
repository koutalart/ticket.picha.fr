import {t, Trans} from "@lingui/macro";
import {CheckInListLiveStats} from "../../../../types.ts";
import classes from "./LiveCounters.module.scss";

interface LiveCountersProps {
    stats?: CheckInListLiveStats;
    variant?: 'page' | 'scanner';
}

/**
 * « Entrés » and « Encore dehors » for the person at the door. Paper « Sur place » tickets only count once scanned.
 */
export const LiveCounters = ({stats, variant = 'page'}: LiveCountersProps) => {
    const value = (count?: number) => count === undefined ? '…' : count.toLocaleString('fr-FR');

    return (
        <div className={`${classes.counters} ${variant === 'scanner' ? classes.scanner : ''}`} aria-live="polite">
            <div className={`${classes.counter} ${classes.inside}`}>
                <span className={classes.label}>{t`Checked in`}</span>
                <span className={classes.value}>{value(stats?.checked_in)}</span>
                {!!stats?.paper_checked_in && (
                    <span className={classes.detail}>
                        <Trans>incl. {stats.paper_checked_in} on-site tickets</Trans>
                    </span>
                )}
            </div>
            <div className={`${classes.counter} ${classes.outside}`}>
                <span className={classes.label}>{t`Still outside`}</span>
                <span className={classes.value}>{value(stats?.outside)}</span>
                {stats !== undefined && (
                    <span className={classes.detail}>
                        <Trans>out of {stats.expected} expected</Trans>
                    </span>
                )}
            </div>
        </div>
    );
};
