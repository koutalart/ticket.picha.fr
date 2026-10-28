import {t} from "@lingui/macro";
import {SmsCampaignRecipientStatus, SmsCampaignStatus, SmsConversationStatus, SmsMessage} from "../../../../types.ts";

export const conversationStatusLabel = (status: SmsConversationStatus): string => {
    switch (status) {
        case 'NEEDS_HUMAN':
            return t`To handle`;
        case 'CLOSED':
            return t`Closed`;
        default:
            return t`In progress`;
    }
};

export const messageStatusLabel = (message: SmsMessage): string | null => {
    switch (message.status) {
        case 'QUEUED':
            return t`In the sending queue`;
        case 'PENDING':
            return t`Sending`;
        case 'SENT':
            return t`Sent`;
        case 'DELIVERED':
            return t`Delivered`;
        case 'FAILED':
            return t`Failed`;
        case 'EXPIRED':
            return t`Not sent (phone offline)`;
        default:
            return null;
    }
};

export const messageAuthorLabel = (message: SmsMessage): string | null => {
    if (message.channel === 'WEB_CHAT') {
        return message.direction === 'INBOUND' ? t`Web chat` : t`Anicka (web chat)`;
    }

    if (message.auto_sent) {
        return message.auto_reply_type === 'HOLDING' ? t`Holding reply sent automatically` : t`Sent automatically`;
    }

    switch (message.author_type) {
        case 'CAMPAIGN':
            return t`Campaign`;
        case 'AUTOMATIC':
            return t`Automatic message`;
        case 'AGENT':
            return t`Assistant`;
        default:
            return null;
    }
};

export const recipientStatusLabel = (status: SmsCampaignRecipientStatus): string => {
    switch (status) {
        case 'SCHEDULED':
            return t`Scheduled`;
        case 'SENT':
            return t`Sent`;
        case 'DELIVERED':
            return t`Delivered`;
        case 'FAILED':
            return t`Failed`;
        case 'EXPIRED':
            return t`Not sent (phone offline)`;
        default:
            return t`Waiting`;
    }
};

export const recipientStatusColor = (status: SmsCampaignRecipientStatus): string => {
    switch (status) {
        case 'DELIVERED':
            return 'teal';
        case 'SENT':
            return 'blue';
        case 'FAILED':
        case 'EXPIRED':
            return 'red';
        default:
            return 'gray';
    }
};

export const reminderStatusLabel = (status: SmsCampaignStatus | null): string => {
    switch (status) {
        case 'PROCESSING':
            return t`Sending in progress`;
        case 'SENT':
            return t`Sent`;
        case 'FAILED':
            return t`Failed`;
        case 'CANCELLED':
            return t`Cancelled`;
        default:
            return t`Scheduled`;
    }
};

export const reminderStatusColor = (status: SmsCampaignStatus | null): string => {
    switch (status) {
        case 'PROCESSING':
            return 'blue';
        case 'SENT':
            return 'teal';
        case 'FAILED':
            return 'red';
        case 'CANCELLED':
            return 'gray';
        default:
            return 'yellow';
    }
};
