import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {ActionIcon, Alert, Badge, Button, Loader, Select, Text, Textarea, UnstyledButton} from "@mantine/core";
import {IconArrowLeft, IconBan, IconRefresh, IconSend, IconSparkles, IconUserExclamation} from "@tabler/icons-react";
import {IdParam, SmsConversationDetail, SmsConversationStatus} from "../../../../types.ts";
import {useSendSmsReply} from "../../../../mutations/useSendSmsReply.ts";
import {useUpdateSmsConversation} from "../../../../mutations/useUpdateSmsConversation.ts";
import {useRegenerateSmsDraft} from "../../../../mutations/useRegenerateSmsDraft.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {relativeDate} from "../../../../utilites/dates.ts";
import {conversationStatusLabel, messageAuthorLabel, messageStatusLabel} from "./labels.ts";
import {countSmsSegments} from "./smsSegments.ts";
import classes from "./Sms.module.scss";

const MAX_LENGTH = 640;

interface ConversationThreadProps {
    organizerId: IdParam;
    detail: SmsConversationDetail | undefined;
    isLoading: boolean;
    canSend: boolean;
    assistantEnabled: boolean;
    onBack: () => void;
    onOpenCustomer: () => void;
}

export const ConversationThread = ({organizerId, detail, isLoading, canSend, assistantEnabled, onBack, onOpenCustomer}: ConversationThreadProps) => {
    const [content, setContent] = useState('');
    const [error, setError] = useState<string | null>(null);
    const sendMutation = useSendSmsReply();
    const updateMutation = useUpdateSmsConversation();
    const regenerateMutation = useRegenerateSmsDraft();
    const bottomRef = useRef<HTMLDivElement>(null);
    const shownDraftRef = useRef<string | null>(null);
    const conversation = detail?.conversation;
    const messageCount = detail?.messages.length ?? 0;

    useEffect(() => {
        setContent(conversation?.draft_reply || '');
        shownDraftRef.current = conversation?.draft_reply || null;
        setError(null);
    }, [conversation?.id]);

    useEffect(() => {
        const draft = conversation?.draft_reply || null;
        if (draft && draft !== shownDraftRef.current) {
            setContent(current => (!current.trim() || current === shownDraftRef.current) ? draft : current);
        }
        shownDraftRef.current = draft;
    }, [conversation?.draft_reply]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({block: 'end'});
    }, [conversation?.id, messageCount]);

    if (isLoading || !detail || !conversation) {
        return (
            <div className={`${classes.threadColumn} ${classes.centered}`}>
                {isLoading ? <Loader size="sm"/> : <Text size="sm" c="dimmed">{t`Select a conversation`}</Text>}
            </div>
        );
    }

    const segments = countSmsSegments(content);
    const needsHumanReason = conversation.needs_human_reason;
    const isDraft = !!conversation.draft_reply && content === conversation.draft_reply;

    const send = () => {
        const trimmed = content.trim();
        if (!trimmed) {
            return;
        }

        setError(null);
        sendMutation.mutate({organizerId, conversationId: conversation.id, content: trimmed}, {
            onSuccess: () => setContent(''),
            onError: (exception: any) => {
                const response = exception?.response?.data;
                setError(response?.errors?.content?.[0] || response?.message || t`The SMS could not be sent`);
            },
        });
    };

    const regenerate = () => {
        setError(null);
        regenerateMutation.mutate({organizerId, conversationId: conversation.id}, {
            onSuccess: ({data}) => {
                shownDraftRef.current = data.draft_reply;
                setContent(data.draft_reply || '');
            },
            onError: (exception: any) => showError(exception?.response?.data?.message || t`The assistant could not suggest a reply. Please try again.`),
        });
    };

    const changeStatus = (status: string | null) => {
        if (!status) {
            return;
        }

        updateMutation.mutate(
            {organizerId, conversationId: conversation.id, status: status as SmsConversationStatus},
            {onError: () => showError(t`Unable to update the conversation`)},
        );
    };

    return (
        <div className={classes.threadColumn}>
            <div className={classes.threadHeader}>
                <ActionIcon variant="subtle" className={classes.backButton} onClick={onBack} aria-label={t`Back`}>
                    <IconArrowLeft size={18}/>
                </ActionIcon>
                <UnstyledButton className={classes.threadTitle} onClick={onOpenCustomer} title={t`Open the customer file`}>
                    <strong className={classes.threadName}>{conversation.contact_name || conversation.phone}</strong>
                    {conversation.contact_name && <Text size="xs" c="dimmed">{conversation.phone}</Text>}
                </UnstyledButton>
                <Select
                    size="xs"
                    w={150}
                    value={conversation.status}
                    onChange={changeStatus}
                    allowDeselect={false}
                    data={(['OPEN', 'NEEDS_HUMAN', 'CLOSED'] as SmsConversationStatus[]).map(status => ({
                        value: status,
                        label: conversationStatusLabel(status),
                    }))}
                />
            </div>

            <div className={classes.messages}>
                {detail.messages.map(message => {
                    const statusLabel = messageStatusLabel(message);
                    const authorLabel = messageAuthorLabel(message);
                    const date = message.provider_timestamp || message.created_at;

                    return (
                        <div
                            key={message.id}
                            className={classes.bubbleRow}
                            data-direction={message.direction}
                        >
                            <div className={classes.bubble} data-direction={message.direction} data-failed={message.status === 'FAILED' || message.status === 'EXPIRED' || undefined}>
                                <div className={classes.bubbleContent}>{message.content}</div>
                                <div className={classes.bubbleMeta}>
                                    {authorLabel && <span>{authorLabel}</span>}
                                    <span>{relativeDate(date)}</span>
                                    {statusLabel && <span>{statusLabel}</span>}
                                </div>
                                {message.error_message && <div className={classes.bubbleError}>{message.error_message}</div>}
                            </div>
                        </div>
                    );
                })}
                <div ref={bottomRef}/>
            </div>

            <div className={classes.composer}>
                {conversation.status === 'NEEDS_HUMAN' && needsHumanReason && (
                    <Alert color="orange" variant="light" icon={<IconUserExclamation size={16}/>} mb="xs">
                        {t`To handle by your team: ${needsHumanReason}`}
                    </Alert>
                )}
                {detail.opted_out ? (
                    <Alert color="red" variant="light" icon={<IconBan size={16}/>}>
                        {t`This number replied STOP: it can no longer receive SMS from you. If they reply START, you will be able to write to them again.`}
                    </Alert>
                ) : !canSend ? (
                    <Alert color="yellow" variant="light">
                        {t`Add your httpSMS API key and sending number in Settings to reply from here.`}
                    </Alert>
                ) : (
                    <>
                        {isDraft && (
                            <Badge color="violet" variant="light" leftSection={<IconSparkles size={12}/>} mb={6}>
                                {t`Suggested reply, check it before sending`}
                            </Badge>
                        )}
                        <Textarea
                            value={content}
                            onChange={(event) => setContent(event.currentTarget.value)}
                            placeholder={t`Write your reply`}
                            autosize
                            minRows={2}
                            maxRows={8}
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
                                {t`${segments.characters} characters, ${segments.segments} SMS`}
                                {segments.unicode ? ` ${t`(emoji or special characters: 70 characters per SMS)`}` : ''}
                            </Text>
                            <div className={classes.composerActions}>
                            {assistantEnabled && (
                                <Button
                                    variant="subtle"
                                    leftSection={<IconRefresh size={16}/>}
                                    onClick={regenerate}
                                    loading={regenerateMutation.isPending}
                                >
                                    {t`Regenerate the suggestion`}
                                </Button>
                            )}
                            <Button
                                leftSection={<IconSend size={16}/>}
                                onClick={send}
                                loading={sendMutation.isPending}
                                disabled={!content.trim()}
                            >
                                {t`Send`}
                            </Button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
};
