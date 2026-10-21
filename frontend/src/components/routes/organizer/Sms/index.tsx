import {useEffect, useState} from "react";
import {useQueryClient} from "@tanstack/react-query";
import {NavLink, useParams, useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Anchor} from "@mantine/core";
import {useDebouncedValue} from "@mantine/hooks";
import {SmsConversation, SmsConversationFilter} from "../../../../types.ts";
import {GET_SMS_CONVERSATIONS_QUERY_KEY, SMS_POLL_INTERVAL_MS, useGetSmsConversations} from "../../../../queries/useGetSmsConversations.ts";
import {GET_SMS_UNREAD_COUNT_QUERY_KEY} from "../../../../queries/useGetSmsUnreadCount.ts";
import {useGetSmsConversation} from "../../../../queries/useGetSmsConversation.ts";
import {useGetSmsSettings} from "../../../../queries/useGetSmsSettings.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import {PageBody} from "../../../common/PageBody";
import {ConversationList} from "./ConversationList.tsx";
import {ConversationThread} from "./ConversationThread.tsx";
import {CustomerCard} from "./CustomerCard.tsx";
import classes from "./Sms.module.scss";

const CONVERSATION_PARAM = 'conversation';

const Sms = () => {
    const {organizerId} = useParams();
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const [filter, setFilter] = useState<SmsConversationFilter>('all');
    const selectedId = Number(searchParams.get(CONVERSATION_PARAM)) || null;

    const {data: organizer} = useGetOrganizer(organizerId);
    const settingsQuery = useGetSmsSettings(organizerId, SMS_POLL_INTERVAL_MS * 4);
    const conversationsQuery = useGetSmsConversations(organizerId, {query: debouncedSearch || undefined, filter});
    const detailQuery = useGetSmsConversation(organizerId, selectedId ?? undefined);

    const conversations = conversationsQuery.data?.data ?? [];
    const settings = settingsQuery.data;
    const canSend = !!settings?.api_key_configured && !!settings?.from_number;

    useEffect(() => {
        const listed = conversations.find(conversation => conversation.id === selectedId);
        if (detailQuery.data && listed && listed.unread_count > 0) {
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, organizerId]});
            queryClient.invalidateQueries({queryKey: [GET_SMS_UNREAD_COUNT_QUERY_KEY, organizerId]});
        }
    }, [detailQuery.dataUpdatedAt]);

    const select = (conversation: SmsConversation | null) => {
        const next = new URLSearchParams(searchParams);
        if (conversation) {
            next.set(CONVERSATION_PARAM, String(conversation.id));
        } else {
            next.delete(CONVERSATION_PARAM);
        }
        setSearchParams(next, {replace: true});
    };

    return (
        <PageBody>
            <div className={classes.pageHeader}>
                <h1 className={classes.pageTitle}>{t`SMS messages`}</h1>
                <p className={classes.pageIntro}>
                    {t`Replies from your customers to your SMS, sent from your httpSMS phone. A customer who replies STOP is automatically unsubscribed from your SMS.`}
                </p>
            </div>

            {settingsQuery.isFetched && !canSend && (
                <Alert color="yellow" variant="light" mb="md">
                    {t`SMS is not connected yet.`}{' '}
                    <Anchor component={NavLink} to={`/manage/organizer/${organizerId}/settings#sms`} size="sm">
                        {t`Connect httpSMS in Settings`}
                    </Anchor>
                </Alert>
            )}

            {canSend && settings?.phone_status === 'OFFLINE' && (
                <Alert color="red" variant="light" mb="md" title={t`Your httpSMS phone is offline`}>
                    {settings.phone_status_changed_at
                        ? t`The phone went offline ${relativeDate(settings.phone_status_changed_at)}. New SMS will not arrive here and your replies will not be sent. Check that the phone is switched on, connected to the internet and that the httpSMS app is running.`
                        : t`New SMS will not arrive here and your replies will not be sent. Check that the phone is switched on, connected to the internet and that the httpSMS app is running.`}
                </Alert>
            )}

            <div className={classes.inbox} data-has-selection={selectedId ? true : undefined}>
                <ConversationList
                    conversations={conversations}
                    isLoading={conversationsQuery.isLoading}
                    selectedId={selectedId}
                    onSelect={select}
                    search={search}
                    onSearchChange={setSearch}
                    filter={filter}
                    onFilterChange={setFilter}
                />
                <ConversationThread
                    organizerId={organizerId}
                    detail={selectedId ? detailQuery.data : undefined}
                    isLoading={!!selectedId && detailQuery.isLoading}
                    canSend={canSend}
                    onBack={() => select(null)}
                />
                <CustomerCard
                    detail={selectedId ? detailQuery.data : undefined}
                    timezone={organizer?.timezone || 'Indian/Mayotte'}
                />
            </div>
        </PageBody>
    );
};

export default Sms;
