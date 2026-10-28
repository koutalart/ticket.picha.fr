import {useState} from "react";
import {t} from "@lingui/macro";
import {Alert, Anchor, Badge, Button, Drawer, Group, Pagination, Skeleton, Stack, Text} from "@mantine/core";
import {IconMail, IconMessage, IconPhone} from "@tabler/icons-react";
import {IdParam, LiveChatSession} from "../../../../types.ts";
import {useGetOrganizerLiveChats} from "../../../../queries/useGetOrganizerLiveChats.ts";
import {useGetOrganizerLiveChat} from "../../../../queries/useGetOrganizerLiveChat.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import classes from "./Sms.module.scss";

interface LiveChatListProps {
    organizerId: IdParam;
    onOpenConversation: (conversationId: number) => void;
}

const contactLabel = (session: LiveChatSession) => session.visitor_phone || session.visitor_email || t`No contact details`;

const ASSISTANT_NAME = 'Anicka';

/**
 * « Chats web »: what visitors asked in the chat of the public pages. Chats with a phone number are also
 * in the SMS conversations (« To handle »); chats without contact details are only kept here, for 90 days.
 */
export const LiveChatList = ({organizerId, onOpenConversation}: LiveChatListProps) => {
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const listQuery = useGetOrganizerLiveChats(organizerId, page);
    const detailQuery = useGetOrganizerLiveChat(organizerId, selectedId);
    const sessions = listQuery.data?.data ?? [];
    const lastPage = listQuery.data?.meta?.last_page ?? 1;
    const detail = detailQuery.data;
    const visitorContact = detail ? [detail.visitor_phone, detail.visitor_email].filter(Boolean).join(', ') : '';

    if (listQuery.isLoading) {
        return <Skeleton height={220} radius="md"/>;
    }

    if (sessions.length === 0) {
        return (
            <Alert variant="light" color="gray">
                {t`No web chat yet. The questions asked by visitors in the chat of your public pages will appear here.`}
            </Alert>
        );
    }

    return (
        <div className={classes.campaigns}>
            <Text size="sm" c="dimmed">
                {t`Chats where the visitor left a phone number are also in your SMS conversations, marked "To handle". Chats without contact details are deleted after 90 days.`}
            </Text>
            {sessions.map((session) => (
                <section key={session.id} className={classes.campaign}>
                    <Group justify="space-between" wrap="nowrap" align="flex-start">
                        <div>
                            <Text fw={600}>{contactLabel(session)}</Text>
                            <Text size="sm" c="dimmed">
                                {relativeDate(session.last_message_at || session.created_at)}
                                {', '}
                                {t`${session.visitor_message_count} message(s) from the visitor`}
                            </Text>
                            {session.needs_follow_up && session.follow_up_reason && (
                                <Text size="sm" mt={4}>{session.follow_up_reason}</Text>
                            )}
                        </div>
                        <Group gap="xs" wrap="nowrap">
                            {session.refunded_at && <Badge color="green" variant="light">{t({message: 'Refunded', context: 'web chat'})}</Badge>}
                            {session.needs_follow_up && <Badge color="orange" variant="light">{t`To follow up`}</Badge>}
                            <Button variant="light" size="xs" onClick={() => setSelectedId(session.id)}>{t`Read`}</Button>
                        </Group>
                    </Group>
                </section>
            ))}

            {lastPage > 1 && <Pagination value={page} onChange={setPage} total={lastPage} size="sm"/>}

            <Drawer
                opened={selectedId !== null}
                onClose={() => setSelectedId(null)}
                position="right"
                title={detail ? contactLabel(detail) : t`Web chat`}
                size="md"
            >
                {detailQuery.isLoading && <Skeleton height={200}/>}
                {detail && (
                    <Stack gap="sm">
                        <Text size="sm">
                            {visitorContact !== ''
                                ? t`Visitor contact details: ${visitorContact}`
                                :t`The visitor left no contact details. Answers to their questions are shown in their chat.`}
                        </Text>
                        <Group gap="xs">
                            {detail.visitor_phone && (
                                <Button
                                    size="xs"
                                    variant="light"
                                    component="a"
                                    href={`tel:${detail.visitor_phone}`}
                                    leftSection={<IconPhone size={14}/>}
                                >
                                    {t`Call`}
                                </Button>
                            )}
                            {detail.sms_conversation_id && (
                                <Button
                                    size="xs"
                                    variant="light"
                                    leftSection={<IconMessage size={14}/>}
                                    onClick={() => onOpenConversation(detail.sms_conversation_id!)}
                                >
                                    {t`Open the SMS conversation`}
                                </Button>
                            )}
                            {detail.visitor_email && (
                                <Button
                                    size="xs"
                                    variant="light"
                                    component="a"
                                    href={`mailto:${detail.visitor_email}`}
                                    leftSection={<IconMail size={14}/>}
                                >
                                    {t`Reply by email`}
                                </Button>
                            )}
                        </Group>
                        {(detail.messages ?? []).map((message) => (
                            <div key={message.id} className={classes.liveChatMessage} data-author={message.author_type}>
                                <Text size="xs" c="dimmed">
                                    {message.author_type === 'VISITOR' ? t`Visitor` : ASSISTANT_NAME}
                                    {', '}
                                    {new Date(message.created_at).toLocaleString(undefined, {dateStyle: 'short', timeStyle: 'short'})}
                                </Text>
                                <Text size="sm" style={{whiteSpace: 'pre-wrap'}}>{message.content}</Text>
                            </div>
                        ))}
                        {detail.visitor_email && !detail.sms_conversation_id && (
                            <Text size="xs" c="dimmed">
                                {t`You received this chat by email too: reply to that email to answer the visitor from your mailbox.`}{' '}
                                <Anchor href={`mailto:${detail.visitor_email}`} size="xs">{detail.visitor_email}</Anchor>
                            </Text>
                        )}
                    </Stack>
                )}
            </Drawer>
        </div>
    );
};
