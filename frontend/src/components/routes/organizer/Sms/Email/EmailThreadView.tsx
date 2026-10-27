import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {ActionIcon, Alert, Anchor, Badge, Button, Loader, Select, Text, Textarea, UnstyledButton} from "@mantine/core";
import {IconArrowLeft, IconBan, IconPaperclip, IconSend} from "@tabler/icons-react";
import {NavLink} from "react-router";
import {EmailMessage, EmailThreadDetail, EmailThreadStatus, IdParam} from "../../../../../types.ts";
import {useReplyEmailThread} from "../../../../../mutations/useReplyEmailThread.ts";
import {useUpdateEmailThread} from "../../../../../mutations/useUpdateEmailThread.ts";
import {showError} from "../../../../../utilites/notifications.tsx";
import {formatCurrency} from "../../../../../utilites/currency.ts";
import {relativeDate} from "../../../../../utilites/dates.ts";
import {
    emailSourceLabel,
    emailStatusColor,
    emailStatusLabel,
    emailThreadStatusLabel,
    isFailedEmailStatus,
    splitQuotedReply,
} from "./emailLabels.ts";
import classes from "../Sms.module.scss";
import emailClasses from "./Email.module.scss";

const MAX_LENGTH = 10000;

interface EmailThreadViewProps {
    organizerId: IdParam;
    detail: EmailThreadDetail | undefined;
    isLoading: boolean;
    onBack: () => void;
}

const EmailMessageBubble = ({message}: {message: EmailMessage}) => {
    const [showQuoted, setShowQuoted] = useState(false);
    const inbound = message.direction === 'INBOUND';
    const source = emailSourceLabel(message.source);
    const text = message.body_text ?? '';
    const {reply, quoted} = inbound ? splitQuotedReply(text) : {reply: text, quoted: ''};
    const failed = isFailedEmailStatus(message.status);

    return (
        <div className={classes.bubbleRow} data-direction={message.direction}>
            <div
                className={`${classes.bubble} ${emailClasses.emailBubble}`}
                data-direction={message.direction}
                data-failed={failed || undefined}
            >
                {message.subject && <div className={emailClasses.emailSubject}>{message.subject}</div>}

                {reply ? (
                    <div className={emailClasses.emailBody}>{reply}</div>
                ) : message.body_html ? (
                    <div className={emailClasses.emailHtml} dangerouslySetInnerHTML={{__html: message.body_html}}/>
                ) : (
                    message.preview && <div className={emailClasses.emailBody}>{message.preview}</div>
                )}

                {quoted && (
                    <>
                        <Anchor component="button" type="button" size="xs" c="inherit" td="underline" onClick={() => setShowQuoted(!showQuoted)}>
                            {showQuoted ? t`Hide the quoted message` : t`Show the quoted message`}
                        </Anchor>
                        {showQuoted && <div className={emailClasses.quoted}>{quoted}</div>}
                    </>
                )}

                {!!message.attachments?.length && (
                    <div className={emailClasses.attachments}>
                        {message.attachments.map((attachment, index) => (
                            <Badge key={index} size="sm" variant="light" color="gray" className={emailClasses.metaBadge}
                                   leftSection={<IconPaperclip size={12}/>}>
                                {attachment.filename || t`Attachment`}
                            </Badge>
                        ))}
                    </div>
                )}

                <div className={classes.bubbleMeta}>
                    {source && <span>{source}</span>}
                    <span>{relativeDate(message.created_at)}</span>
                    {!inbound && (
                        <Badge size="xs" variant={failed ? 'filled' : 'light'} color={emailStatusColor(message.status)} className={emailClasses.metaBadge}>
                            {emailStatusLabel(message.status)}
                        </Badge>
                    )}
                </div>
                {message.error_message && <div className={classes.bubbleError}>{message.error_message}</div>}
            </div>
        </div>
    );
};

export const EmailThreadView = ({organizerId, detail, isLoading, onBack}: EmailThreadViewProps) => {
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const replyMutation = useReplyEmailThread();
    const updateMutation = useUpdateEmailThread();
    const bottomRef = useRef<HTMLDivElement>(null);
    const thread = detail?.thread;
    const messageCount = detail?.messages.length ?? 0;

    useEffect(() => {
        setBody('');
        setError(null);
    }, [thread?.id]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({block: 'end'});
    }, [thread?.id, messageCount]);

    if (isLoading || !detail || !thread) {
        return (
            <div className={`${classes.threadColumn} ${classes.centered}`}>
                {isLoading ? <Loader size="sm"/> : <Text size="sm" c="dimmed">{t`Select a conversation`}</Text>}
            </div>
        );
    }

    const send = () => {
        const trimmed = body.trim();
        if (!trimmed) {
            return;
        }

        setError(null);
        replyMutation.mutate({organizerId, threadId: thread.id, body: trimmed}, {
            onSuccess: () => setBody(''),
            onError: (exception: any) => {
                const response = exception?.response?.data;
                setError(response?.errors?.body?.[0] || response?.message || t`The e-mail could not be sent`);
            },
        });
    };

    const changeStatus = (status: string | null) => {
        if (!status) {
            return;
        }

        updateMutation.mutate(
            {organizerId, threadId: thread.id, status: status as EmailThreadStatus},
            {onError: () => showError(t`Unable to update the conversation`)},
        );
    };

    const lastOrder = detail.customer_orders[0];

    return (
        <div className={classes.threadColumn}>
            <div className={classes.threadHeader}>
                <ActionIcon variant="subtle" className={classes.backButton} onClick={onBack} aria-label={t`Back`}>
                    <IconArrowLeft size={18}/>
                </ActionIcon>
                <UnstyledButton
                    className={classes.threadTitle}
                    component={NavLink}
                    to={`/manage/organizer/${organizerId}/contacts?query=${encodeURIComponent(thread.email)}`}
                    title={t`Open the contact`}
                >
                    <strong className={classes.threadName}>{thread.contact_name || thread.email}</strong>
                    {thread.contact_name && <Text size="xs" c="dimmed">{thread.email}</Text>}
                    {lastOrder && (
                        <Text size="xs" c="dimmed">
                            {t`${detail.customer_orders.length} orders, last: ${lastOrder.event_title} (${formatCurrency(lastOrder.total_gross, lastOrder.currency)})`}
                        </Text>
                    )}
                </UnstyledButton>
                <Select
                    size="xs"
                    w={130}
                    value={thread.status}
                    onChange={changeStatus}
                    allowDeselect={false}
                    data={(['OPEN', 'NEEDS_HUMAN', 'CLOSED'] as EmailThreadStatus[]).map(status => ({
                        value: status,
                        label: emailThreadStatusLabel(status),
                    }))}
                />
            </div>

            <div className={classes.messages}>
                {detail.messages.map(message => <EmailMessageBubble key={message.id} message={message}/>)}
                <div ref={bottomRef}/>
            </div>

            <div className={classes.composer}>
                {detail.unsubscribed && (
                    <Alert color="yellow" variant="light" icon={<IconBan size={16}/>} mb="xs">
                        {t`This contact unsubscribed from your e-mails. Only answer a question they asked you.`}
                    </Alert>
                )}
                <Textarea
                    value={body}
                    onChange={(event) => setBody(event.currentTarget.value)}
                    placeholder={t`Write your reply`}
                    autosize
                    minRows={3}
                    maxRows={10}
                    maxLength={MAX_LENGTH}
                    error={error}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                            event.preventDefault();
                            send();
                        }
                    }}
                />
                <div className={classes.composerFooter}>
                    <Text size="xs" c="dimmed">
                        {t`Sent by e-mail to ${thread.email}. Their answer will come back here.`}
                    </Text>
                    <div className={classes.composerActions}>
                        <Button
                            size="sm"
                            className={classes.composerSend}
                            leftSection={<IconSend size={16}/>}
                            onClick={send}
                            loading={replyMutation.isPending}
                            disabled={!body.trim()}
                        >
                            {t`Send`}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
};
