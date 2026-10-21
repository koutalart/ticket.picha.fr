import {t} from "@lingui/macro";
import {SmsCampaignRecipientStatus, SmsConversationStatus, SmsMessage} from "../../../../types.ts";

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
    switch (message.author_type) {
        case 'CAMPAIGN':
            return t`Campaign`;
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
