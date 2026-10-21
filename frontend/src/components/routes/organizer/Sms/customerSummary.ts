import {t} from "@lingui/macro";
import {SmsConversationDetail} from "../../../../types.ts";
import {relativeDate} from "../../../../utilites/dates.ts";

const MAX_EVENTS = 2;

const listEvents = (titles: string[]): string => {
    const unique = Array.from(new Set(titles));
    const shown = unique.slice(0, MAX_EVENTS).join(', ');
    return unique.length > MAX_EVENTS ? `${shown}…` : shown;
};

export const buildCustomerSummary = (detail: SmsConversationDetail, now: Date = new Date()): string => {
    const parts: string[] = [];
    const orders = detail.customer_orders.filter(order => order.status !== 'CANCELLED');
    const upcoming = orders.filter(order => order.event_start_date && new Date(order.event_start_date.replace(' ', 'T') + 'Z') > now);

    if (detail.conversation.status === 'NEEDS_HUMAN') {
        parts.push(t`To handle`);
    }

    if (upcoming.length > 0) {
        parts.push(t`Has a ticket for ${listEvents(upcoming.map(order => order.event_title))}`);
    } else if (orders.length > 0) {
        parts.push(t`Customer of ${listEvents(orders.map(order => order.event_title))}, no ticket for an upcoming event yet`);
    } else {
        parts.push(t`No order with this number`);
    }

    const messages = detail.messages;
    const lastInbound = [...messages].reverse().find(message => message.direction === 'INBOUND');
    const last = messages[messages.length - 1];

    if (detail.opted_out) {
        parts.push(t`Unsubscribed (STOP)`);
    } else if (last && last.direction === 'INBOUND') {
        parts.push(t`Waiting for your reply since ${relativeDate(last.provider_timestamp || last.created_at)}`);
    } else if (last && !lastInbound) {
        parts.push(t`Has not replied yet`);
    } else if (last) {
        parts.push(t`Last message sent ${relativeDate(last.provider_timestamp || last.created_at)}`);
    }

    return parts.join('. ') + '.';
};
