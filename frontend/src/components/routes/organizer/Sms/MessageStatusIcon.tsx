import {Tooltip} from "@mantine/core";
import {IconAlertCircle, IconCheck, IconChecks, IconClock} from "@tabler/icons-react";
import {SmsMessage} from "../../../../types.ts";
import {messageStatusLabel} from "./labels.ts";
import classes from "./Sms.module.scss";

const ICONS = {
    PENDING: IconClock,
    SENT: IconCheck,
    DELIVERED: IconChecks,
    FAILED: IconAlertCircle,
    EXPIRED: IconAlertCircle,
} as const;

export const MessageStatusIcon = ({message}: { message: SmsMessage }) => {
    const label = messageStatusLabel(message);
    const Icon = ICONS[message.status as keyof typeof ICONS];

    if (!label || !Icon || message.direction !== 'OUTBOUND') {
        return null;
    }

    return (
        <Tooltip label={label} withArrow>
            <span className={classes.statusIcon} data-status={message.status} aria-label={label} role="img">
                <Icon size={15} stroke={2}/>
            </span>
        </Tooltip>
    );
};
