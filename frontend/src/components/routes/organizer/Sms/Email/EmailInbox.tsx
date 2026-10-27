import {useEffect, useState} from "react";
import {useQueryClient} from "@tanstack/react-query";
import {useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Button} from "@mantine/core";
import {useDebouncedValue} from "@mantine/hooks";
import {IconMailPlus} from "@tabler/icons-react";
import {EmailThread, EmailThreadFilter, IdParam} from "../../../../../types.ts";
import {GET_EMAIL_THREADS_QUERY_KEY, useGetEmailThreads} from "../../../../../queries/useGetEmailThreads.ts";
import {useGetEmailThread} from "../../../../../queries/useGetEmailThread.ts";
import {GET_EMAIL_UNREAD_COUNT_QUERY_KEY} from "../../../../../queries/useGetEmailUnreadCount.ts";
import {EmailThreadList} from "./EmailThreadList.tsx";
import {EmailThreadView} from "./EmailThreadView.tsx";
import {NewEmailModal} from "./NewEmailModal.tsx";
import classes from "../Sms.module.scss";
import emailClasses from "./Email.module.scss";

const THREAD_PARAM = 'thread';

export const EmailInbox = ({organizerId}: {organizerId: IdParam}) => {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const [filter, setFilter] = useState<EmailThreadFilter>('all');
    const [composeOpen, setComposeOpen] = useState(false);
    const selectedId = Number(searchParams.get(THREAD_PARAM)) || null;

    const threadsQuery = useGetEmailThreads(organizerId, {query: debouncedSearch || undefined, filter});
    const detailQuery = useGetEmailThread(organizerId, selectedId ?? undefined);
    const threads = threadsQuery.data?.pages.flatMap(page => page.data) ?? [];
    const replyTrackingEnabled = threadsQuery.data?.pages[0]?.meta.reply_tracking_enabled ?? true;

    useEffect(() => {
        const listed = threads.find(thread => thread.id === selectedId);
        if (detailQuery.data && listed && listed.unread_count > 0) {
            queryClient.invalidateQueries({queryKey: [GET_EMAIL_THREADS_QUERY_KEY, organizerId]});
            queryClient.invalidateQueries({queryKey: [GET_EMAIL_UNREAD_COUNT_QUERY_KEY, organizerId]});
        }
    }, [detailQuery.dataUpdatedAt]);

    const select = (threadId: number | null) => {
        const next = new URLSearchParams(searchParams);
        if (threadId) {
            next.set(THREAD_PARAM, String(threadId));
        } else {
            next.delete(THREAD_PARAM);
        }
        setSearchParams(next, {replace: true});
    };

    return (
        <>
            <div className={emailClasses.toolbar}>
                {!replyTrackingEnabled ? (
                    <Alert color="yellow" variant="light" p="xs">
                        {t`Replies to your e-mails are not collected here yet. They still reach your support e-mail address.`}
                    </Alert>
                ) : <span/>}
                <Button leftSection={<IconMailPlus size={16}/>} onClick={() => setComposeOpen(true)}>
                    {t`New e-mail`}
                </Button>
            </div>

            <div className={classes.inbox} data-columns="2" data-has-selection={selectedId ? true : undefined}>
                <EmailThreadList
                    threads={threads}
                    isLoading={threadsQuery.isLoading}
                    selectedId={selectedId}
                    onSelect={(thread: EmailThread) => select(thread.id)}
                    search={search}
                    onSearchChange={setSearch}
                    filter={filter}
                    onFilterChange={setFilter}
                    hasMore={!!threadsQuery.hasNextPage}
                    isLoadingMore={threadsQuery.isFetchingNextPage}
                    onLoadMore={() => threadsQuery.fetchNextPage()}
                />
                <EmailThreadView
                    organizerId={organizerId}
                    detail={selectedId ? detailQuery.data : undefined}
                    isLoading={!!selectedId && detailQuery.isLoading}
                    onBack={() => select(null)}
                />
            </div>

            <NewEmailModal
                organizerId={organizerId}
                opened={composeOpen}
                onClose={() => setComposeOpen(false)}
                onSent={(message) => {
                    setComposeOpen(false);
                    select(message.email_thread_id);
                }}
            />
        </>
    );
};
