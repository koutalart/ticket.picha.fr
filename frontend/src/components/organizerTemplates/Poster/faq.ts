import {t} from "@lingui/macro";

export const getFaq = () => [
    {
        question: t`How will I receive my ticket?`,
        answer: t`Right after payment, by e-mail. Show the QR code at the entrance, on your phone or printed.`,
    },
    {
        question: t`Is payment secure?`,
        answer: t`Yes. Payment is handled by a certified payment provider: your card details are never shared with the organizer.`,
    },
    {
        question: t`I haven't received my ticket, what should I do?`,
        answer: t`Check your spam folder first. If it's not there, contact the organizer with the e-mail address used for your order and your ticket will be sent again.`,
    },
    {
        question: t`Can I get a refund?`,
        answer: t`Cancellation and refund conditions are set by the organizer. Contact them before the event for any request.`,
    },
];
