import {SmsConversationDetail} from "../../../../types.ts";

export const SMS_FIRST_NAME_VARIABLE = '{prenom}';

const FIRST_NAME_PATTERN = /\{\s*pr[ée]nom\s*\}/giu;

export const contactFirstName = (detail: SmsConversationDetail): string => {
    const fromOrder = detail.customer_orders.find(order => order.first_name?.trim())?.first_name?.trim();
    if (fromOrder) {
        return fromOrder;
    }

    return detail.conversation.contact_name?.trim().split(/\s+/)[0] ?? '';
};

export const applyReplyTemplate = (content: string, firstName: string): string => {
    if (firstName) {
        return content.replace(FIRST_NAME_PATTERN, firstName);
    }

    return content
        .replace(new RegExp(`[ \\t]*${FIRST_NAME_PATTERN.source}`, 'giu'), '')
        .replace(/[ \t]+([,.!?;:])/g, '$1')
        .trim();
};
