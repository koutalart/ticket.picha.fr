import {FormEvent, useState} from "react";
import {Anchor, Button, Checkbox, Group, Stack, Text, TextInput} from "@mantine/core";
import {t} from "@lingui/macro";
import {LiveChatMessage, LiveChatRefundStep, LiveChatRefundTicket} from "../../../types.ts";
import {
    useConfirmLiveChatRefund,
    useRequestLiveChatRefundCode,
    useVerifyLiveChatRefundCode,
} from "../../../mutations/useLiveChat.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import classes from "./LiveChatWindow.module.scss";

interface LiveChatRefundCardProps {
    sessionId: string;
    onMessages: (messages: LiveChatMessage[]) => void;
    onUnavailable: (askContact: boolean, contactGiven: boolean) => void;
    onDone: () => void;
    onLimit: (notice: string | null) => void;
}

type CardStep = 'identify' | 'code' | 'tickets' | 'summary';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const errorMessage = (error: any, fallback: string): string => {
    const errors = error?.response?.data?.errors;
    const first = errors ? Object.values(errors).flat()[0] : null;

    return (typeof first === 'string' && first) || error?.response?.data?.message || fallback;
};

/**
 * Refund of an order with a cancellation protection: order number and e-mail of the order, code received by e-mail,
 * tickets to cancel, summary. The server checks everything and computes the amount; this card only shows its answers.
 */
export const LiveChatRefundCard = ({sessionId, onMessages, onUnavailable, onDone, onLimit}: LiveChatRefundCardProps) => {
    const [step, setStep] = useState<CardStep>('identify');
    const [reference, setReference] = useState('');
    const [email, setEmail] = useState('');
    const [code, setCode] = useState('');
    const [tickets, setTickets] = useState<LiveChatRefundTicket[]>([]);
    const [maxTickets, setMaxTickets] = useState(0);
    const [currency, setCurrency] = useState('EUR');
    const [selected, setSelected] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);

    const requestMutation = useRequestLiveChatRefundCode();
    const verifyMutation = useVerifyLiveChatRefundCode();
    const confirmMutation = useConfirmLiveChatRefund();

    const handleStep = (data: LiveChatRefundStep) => {
        onMessages(data.messages);
        setError(data.error);

        if (data.step === 'unavailable') {
            onUnavailable(data.ask_contact, data.contact_given);
            return;
        }
        if (data.step === 'refunded') {
            onDone();
            return;
        }
        if (data.step === 'tickets') {
            setTickets(data.tickets);
            setMaxTickets(data.max_tickets);
            setCurrency(data.currency || 'EUR');
            setSelected(data.tickets.length === 1 ? [data.tickets[0].attendee_id] : []);
            setStep('tickets');
            return;
        }
        setStep('code');
    };

    const handleError = (requestError: any) => {
        if (requestError?.response?.status === 429) {
            onLimit(requestError?.response?.data?.message ?? null);
            return;
        }
        setError(errorMessage(requestError, t`Something went wrong. Please try again.`));
    };

    const requestCode = (event?: FormEvent) => {
        event?.preventDefault();
        if (!reference.trim()) {
            setError(t`Enter your order number.`);
            return;
        }
        if (!EMAIL_PATTERN.test(email.trim())) {
            setError(t`This email does not look valid.`);
            return;
        }
        setError(null);
        setCode('');
        requestMutation.mutate(
            {sessionId, orderReference: reference.trim(), email: email.trim()},
            {onSuccess: ({data}) => handleStep(data), onError: handleError},
        );
    };

    const verify = (event: FormEvent) => {
        event.preventDefault();
        const digits = code.replace(/\D/g, '');
        if (digits.length !== 6) {
            setError(t`Enter the 6 digits of the code.`);
            return;
        }
        setError(null);
        verifyMutation.mutate({sessionId, code: digits}, {onSuccess: ({data}) => handleStep(data), onError: handleError});
    };

    const toggle = (attendeeId: number, checked: boolean) => {
        setSelected((current) => checked
            ? [...current.filter((id) => id !== attendeeId), attendeeId]
            : current.filter((id) => id !== attendeeId));
    };

    const chosen = tickets.filter((ticket) => selected.includes(ticket.attendee_id));
    const totalMinor = chosen.reduce((sum, ticket) => sum + ticket.amount_minor, 0);
    const total = formatCurrency(totalMinor / 100, currency);

    const confirm = () => {
        setError(null);
        confirmMutation.mutate(
            {sessionId, attendeeIds: chosen.map((ticket) => ticket.attendee_id)},
            {onSuccess: ({data}) => handleStep(data), onError: handleError},
        );
    };

    return (
        <div className={classes.contactForm} role="group" aria-label={t`Cancellation request`}>
            <Text size="sm" fw={600}>{t`Cancellation request`}</Text>

            {step === 'identify' && (
                <form onSubmit={requestCode} noValidate>
                    <Stack gap={8}>
                        <TextInput
                            label={t`Order number`}
                            description={t`Shown in your confirmation email, for example O-AB12CD3`}
                            value={reference}
                            onChange={(event) => setReference(event.currentTarget.value)}
                            maxLength={40}
                            autoComplete="off"
                            autoCapitalize="characters"
                        />
                        <TextInput
                            label={t`Email used for the order`}
                            type="email"
                            autoComplete="email"
                            value={email}
                            onChange={(event) => setEmail(event.currentTarget.value)}
                            maxLength={255}
                        />
                        {error && <Text size="xs" c="red" role="alert">{error}</Text>}
                        <Group justify="flex-end">
                            <Button type="submit" size="xs" loading={requestMutation.isPending}>{t`Receive a code`}</Button>
                        </Group>
                    </Stack>
                </form>
            )}

            {step === 'code' && (
                <form onSubmit={verify} noValidate>
                    <Stack gap={8}>
                        <TextInput
                            label={t`6-digit code received by email`}
                            value={code}
                            onChange={(event) => setCode(event.currentTarget.value)}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            data-autofocus
                        />
                        {error && <Text size="xs" c="red" role="alert">{error}</Text>}
                        <Group justify="space-between">
                            <Anchor component="button" type="button" size="xs" onClick={() => requestCode()} disabled={requestMutation.isPending}>
                                {t`Send a new code`}
                            </Anchor>
                            <Button type="submit" size="xs" loading={verifyMutation.isPending}>{t`Check the code`}</Button>
                        </Group>
                    </Stack>
                </form>
            )}

            {step === 'tickets' && (
                <Stack gap={8}>
                    <Text size="xs" c="dimmed">
                        {maxTickets < tickets.length
                            ? t`Your protection covers ${maxTickets} ticket(s) of this order.`
                            : t`Choose the tickets to cancel.`}
                    </Text>
                    {tickets.map((ticket) => {
                        const checked = selected.includes(ticket.attendee_id);
                        return (
                            <Checkbox
                                key={ticket.attendee_id}
                                checked={checked}
                                disabled={!checked && selected.length >= maxTickets}
                                onChange={(event) => toggle(ticket.attendee_id, event.currentTarget.checked)}
                                label={`${ticket.name || t`Ticket`}, ${ticket.category}`}
                                description={formatCurrency(ticket.amount_minor / 100, currency)}
                            />
                        );
                    })}
                    {error && <Text size="xs" c="red" role="alert">{error}</Text>}
                    <Group justify="flex-end">
                        <Button size="xs" disabled={chosen.length === 0} onClick={() => setStep('summary')}>{t`Continue`}</Button>
                    </Group>
                </Stack>
            )}

            {step === 'summary' && (
                <Stack gap={8}>
                    {chosen.map((ticket) => (
                        <Text key={ticket.attendee_id} size="sm">
                            {`${ticket.name || t`Ticket`}, ${ticket.category}, ${formatCurrency(ticket.amount_minor / 100, currency)}`}
                        </Text>
                    ))}
                    <Text size="sm" fw={700}>{t`Amount refunded: ${total}`}</Text>
                    <Text size="xs" c="dimmed">{t`The service fees and the option are not refunded.`}</Text>
                    <Text size="xs" c="dimmed">{t`The cancelled tickets will no longer be valid.`}</Text>
                    {error && <Text size="xs" c="red" role="alert">{error}</Text>}
                    <Group justify="space-between">
                        <Anchor component="button" type="button" size="xs" onClick={() => setStep('tickets')} disabled={confirmMutation.isPending}>
                            {t`Back`}
                        </Anchor>
                        <Button size="xs" color="red" loading={confirmMutation.isPending} onClick={confirm}>
                            {t`Confirm the cancellation`}
                        </Button>
                    </Group>
                </Stack>
            )}
        </div>
    );
};
