import {t} from "@lingui/macro";
import {EmailMessageStatus, EmailThreadStatus} from "../../../../../types.ts";

export const emailSourceLabel = (source: string): string | null => {
    switch (source) {
        case 'OrderSummary':
            return t`Order confirmation`;
        case 'AttendeeTicketMail':
            return t`Ticket`;
        case 'EventMessage':
            return t`Message to attendees`;
        case 'OrderRefunded':
            return t`Refund`;
        case 'OrderCancelled':
            return t`Order cancelled`;
        case 'OrderFailed':
        case 'PaymentSuccessButOrderExpiredMail':
            return t`Order not completed`;
        case 'OrderDetailsChangedMail':
        case 'AttendeeDetailsChangedMail':
            return t`Details updated`;
        case 'WaitlistConfirmationMail':
        case 'WaitlistOfferMail':
        case 'WaitlistOfferExpiredMail':
            return t`Waitlist`;
        case 'LiveChatRefundUpdateMail':
            return t`Support`;
        default:
            return null;
    }
};

export const emailStatusLabel = (status: EmailMessageStatus): string => {
    switch (status) {
        case 'SENDING':
            return t`Sending`;
        case 'SENT':
            return t`Sent`;
        case 'DELAYED':
            return t`Delayed`;
        case 'DELIVERED':
            return t`Delivered`;
        case 'OPENED':
            return t`Opened`;
        case 'BOUNCED':
            return t`Bounced`;
        case 'COMPLAINED':
            return t`Marked as spam`;
        case 'FAILED':
            return t`Failed`;
        case 'RECEIVED':
            return t`Received`;
    }
};

export const emailStatusColor = (status: EmailMessageStatus): string => {
    switch (status) {
        case 'DELIVERED':
            return 'green';
        case 'OPENED':
            return 'teal';
        case 'BOUNCED':
        case 'COMPLAINED':
        case 'FAILED':
            return 'red';
        case 'DELAYED':
            return 'orange';
        default:
            return 'gray';
    }
};

export const emailThreadStatusLabel = (status: EmailThreadStatus): string => {
    switch (status) {
        case 'OPEN':
            return t`Open`;
        case 'NEEDS_HUMAN':
            return t`To handle`;
        case 'CLOSED':
            return t`Closed`;
    }
};

export const isFailedEmailStatus = (status?: EmailMessageStatus | null): boolean =>
    status === 'BOUNCED' || status === 'COMPLAINED' || status === 'FAILED';

const QUOTE_START = /^\s*(>|(Le|On)\s.{0,200}(a écrit|wrote)\s*:\s*$|-{2,}\s*(Original Message|Message d'origine)|(De|From)\s*:\s.+@)/i;

export const splitQuotedReply = (text: string): {reply: string; quoted: string} => {
    const lines = text.split(/\r?\n/);
    const index = lines.findIndex(line => QUOTE_START.test(line));

    if (index <= 0) {
        return {reply: text.trim(), quoted: ''};
    }

    return {
        reply: lines.slice(0, index).join('\n').trim(),
        quoted: lines.slice(index).join('\n').trim(),
    };
};
