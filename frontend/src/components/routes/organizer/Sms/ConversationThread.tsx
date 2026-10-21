import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {NavLink} from "react-router";
import {ActionIcon, Alert, Badge, Button, Group, Loader, Menu, Select, Text, Textarea} from "@mantine/core";
import {IconArrowLeft, IconBan, IconMessageBolt, IconSend, IconSettings, IconSparkles} from "@tabler/icons-react";
import {IdParam, SmsConversationDetail, SmsConversationStatus} from "../../../../types.ts";
import {useSendSmsReply} from "../../../../mutations/useSendSmsReply.ts";
import {useUpdateSmsConversation} from "../../../../mutations/useUpdateSmsConversation.ts";
import {useGetSmsReplyTemplates} from "../../../../queries/useGetSmsReplyTemplates.ts";
import {showError} from "../../../../utilites/notifications.tsx";
import {relativeDate} from "../../../../utilites/dates.ts";
import {conversationStatusLabel, messageAuthorLabel, messageStatusLabel} from "./labels.ts";
import {countSmsSegments} from "./smsSegments.ts";
import {applyReplyTemplate, contactFirstName} from "./replyTemplates.ts";
import classes from "./Sms.module.scss";

const MAX_LENGTH = 640;

interface ConversationThreadProps {
    organizerId: IdParam;
    detail: SmsConversationDetail | undefined;
    isLoading: boolean;
    canSend: boolean;
    onBack: () => void;
}

export const ConversationThread = ({organizerId, detail, isLoading, canSend, onBack}: ConversationThreadProps) => {
    const [content, setContent] = useState('');
    const [error, setError] = useState<string | null>(null);
    const sendMutation = useSendSmsReply();
    const updateMutation = useUpdateSmsConversation();
    const bottomRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const templatesQuery = useGetSmsReplyTemplates(canSend ? organizerId : undefined);
    const templates = templatesQuery.data ?? [];
    const conversation = detail?.conversation;
    const messageCount = detail?.messages.length ?? 0;

    useEffect(() => {
        setContent(conversation?.draft_reply || '');
        setError(null);
    }, [conversation?.id]);

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

    const insertTemplate = (templateContent: string) => {
        const text = applyReplyTemplate(templateContent, contactFirstName(detail));
        const textarea = textareaRef.current;
        const start = textarea?.selectionStart ?? content.length;
        const end = textarea?.selectionEnd ?? content.length;
        const before = content.slice(0, start);
        const after = content.slice(end);
        const separator = before && !/\s$/.test(before) ? ' ' : '';
        const next = (before + separator + text + after).slice(0, MAX_LENGTH);

        setContent(next);
        requestAnimationFrame(() => {
            const position = Math.min(next.length, before.length + separator.length + text.length);
            textarea?.focus();
            textarea?.setSelectionRange(position, position);
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
                <div className={classes.threadTitle}>
                    <strong>{conversation.contact_name || conversation.phone}</strong>
                    {conversation.contact_name && <Text size="xs" c="dimmed">{conversation.phone}</Text>}
                </div>
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
                            ref={textareaRef}
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
                            <Group gap="xs" wrap="nowrap">
                                <Menu position="top-end" withinPortal shadow="md" width={280}>
                                    <Menu.Target>
                                        <Button variant="default" leftSection={<IconMessageBolt size={16}/>}>
                                            {t`Templates`}
                                        </Button>
                                    </Menu.Target>
                                    <Menu.Dropdown className={classes.templateMenu}>
                                        {templates.length === 0 && (
                                            <Menu.Label>{t`No reply template yet`}</Menu.Label>
                                        )}
                                        {templates.map(template => (
                                            <Menu.Item key={template.id} onClick={() => insertTemplate(template.content)}>
                                                <Text size="sm" fw={600}>{template.name}</Text>
                                                <Text size="xs" c="dimmed" lineClamp={2}>{template.content}</Text>
                                            </Menu.Item>
                                        ))}
                                        <Menu.Divider/>
                                        <Menu.Item
                                            component={NavLink}
                                            to={`/manage/organizer/${organizerId}/settings#sms-reply-templates`}
                                            leftSection={<IconSettings size={14}/>}
                                        >
                                            {t`Manage reply templates`}
                                        </Menu.Item>
                                    </Menu.Dropdown>
                                </Menu>
                                <Button
                                    leftSection={<IconSend size={16}/>}
                                    onClick={send}
                                    loading={sendMutation.isPending}
                                    disabled={!content.trim()}
                                >
                                    {t`Send`}
                                </Button>
                            </Group>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
};
