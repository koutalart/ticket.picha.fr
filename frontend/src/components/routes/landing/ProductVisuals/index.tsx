import QRCodeImport from "react-qr-code";
import {t} from "@lingui/macro";
import {IconCheck, IconPrinter} from "@tabler/icons-react";
import classes from "./ProductVisuals.module.scss";

const QRCode = (QRCodeImport as unknown as { QRCode?: typeof QRCodeImport }).QRCode ?? QRCodeImport;

const SAMPLE_ATTENDEE = {name: "Camille Martin", organization: "Région Horizon"};
const SAMPLE_TICKET_CODE = "PICHA-A7F3-0412";

interface VisualProps {
    label: string;
    className?: string;
}

export const ScanScreen = ({label, className}: VisualProps) => (
    <div className={`${classes.tablet} ${className ?? ""}`} role="img" aria-label={label}>
        <div className={classes.tabletScreen} aria-hidden="true">
            <div className={classes.tabletBar}>
                <span>{t`Welcome desk · Main entrance`}</span>
                <span className={classes.tabletCount}>367 / 412</span>
            </div>
            <div className={classes.scanResult}>
                <span className={classes.scanCheck}><IconCheck size={30} stroke={3}/></span>
                <strong>{t`Access granted`}</strong>
                <span>{SAMPLE_ATTENDEE.name} · {SAMPLE_ATTENDEE.organization}</span>
            </div>
            <div className={classes.printing}>
                <IconPrinter size={18}/>
                <span>{t`Badge printing…`}</span>
                <span className={classes.progress}><span/></span>
            </div>
        </div>
    </div>
);

export const PrintedBadge = ({label, className}: VisualProps) => (
    <div className={`${classes.badge} ${className ?? ""}`} role="img" aria-label={label}>
        <div className={classes.badgeHole} aria-hidden="true"/>
        <div className={classes.badgeBand} aria-hidden="true">{t`Annual Convention`}</div>
        <div className={classes.badgeBody} aria-hidden="true">
            <strong className={classes.badgeName}>{SAMPLE_ATTENDEE.name}</strong>
            <span className={classes.badgeOrg}>{SAMPLE_ATTENDEE.organization}</span>
            <span className={classes.badgeRole}>{t`Speaker`}</span>
            <div className={classes.badgeQr}>
                <QRCode value={SAMPLE_TICKET_CODE} size={128} fgColor="#1d1d1f" style={{width: "100%", height: "auto"}}/>
            </div>
        </div>
    </div>
);

export const InvitationPanel = ({label, className}: VisualProps) => {
    const rows = [
        {name: "Camille Martin", status: t`Registered`, tone: "success"},
        {name: "Yanis Bernard", status: t`Registered`, tone: "success"},
        {name: "Inès Robert", status: t`Reminder sent`, tone: "pending"},
        {name: "Hugo Petit", status: t`Invited`, tone: "neutral"},
    ];

    return (
        <div className={`${classes.panel} ${className ?? ""}`} role="img" aria-label={label}>
            <div aria-hidden="true">
                <div className={classes.panelHeader}>
                    <strong>{t`Invitations`}</strong>
                    <span className={classes.panelPill}>{t`Annual Convention`}</span>
                </div>
                <div className={classes.stats}>
                    <div><strong>248</strong><span>{t`sent`}</span></div>
                    <div><strong>173</strong><span>{t`registered`}</span></div>
                    <div><strong>70%</strong><span>{t`response rate`}</span></div>
                </div>
                <ul className={classes.rows}>
                    {rows.map((row) => (
                        <li key={row.name}>
                            <span className={classes.avatar}>{row.name.charAt(0)}</span>
                            <span className={classes.rowName}>{row.name}</span>
                            <span className={`${classes.status} ${classes[row.tone]}`}>{row.status}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
};

export const LiveDashboard = ({label, className}: VisualProps) => {
    const arrivals = [12, 38, 86, 124, 71, 36];
    const hours = ["8h", "8h30", "9h", "9h30", "10h", "10h30"];
    const max = Math.max(...arrivals);

    return (
        <div className={`${classes.panel} ${className ?? ""}`} role="img" aria-label={label}>
            <div aria-hidden="true">
                <div className={classes.panelHeader}>
                    <strong>{t`Live attendance`}</strong>
                    <span className={classes.live}>{t`Live`}</span>
                </div>
                <div className={classes.stats}>
                    <div><strong>412</strong><span>{t`registered`}</span></div>
                    <div><strong>367</strong><span>{t`present`}</span></div>
                    <div><strong>89%</strong><span>{t`attendance`}</span></div>
                </div>
                <div className={classes.chart}>
                    {arrivals.map((value, index) => (
                        <div key={hours[index]} className={classes.bar}>
                            <span style={{height: `calc((100% - 18px) * ${value / max})`}}/>
                            <small>{hours[index]}</small>
                        </div>
                    ))}
                </div>
                <div className={classes.exportRow}>
                    <span>{t`Attendance sheet`}</span>
                    <span className={classes.exportTag}>PDF · CSV</span>
                </div>
            </div>
        </div>
    );
};
