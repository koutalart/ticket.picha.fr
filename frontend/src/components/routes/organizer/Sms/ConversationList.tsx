import {t} from "@lingui/macro";
import {useEffect, useRef} from "react";
import {Badge, Loader, SegmentedControl, Text, TextInput, UnstyledButton} from "@mantine/core";
import {IconSearch} from "@tabler/icons-react";
import {SmsConversation, SmsConversationFilter} from "../../../../types.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import {conversationStatusLabel} from "./labels.ts";
import classes from "./Sms.module.scss";

interface ConversationListProps {
    conversations: SmsConversation[];
    isLoading: boolean;
    selectedId: number | null;
    onSelect: (conversation: SmsConversation) => void;
    search: string;
    onSearchChange: (value: string) => void;
    filter: SmsConversationFilter;
    onFilterChange: (value: SmsConversationFilter) => void;
    hasMore: boolean;
    isLoadingMore: boolean;
    onLoadMore: () => void;
}

export const ConversationList = ({
                                     conversations,
                                     isLoading,
                                     selectedId,
                                     onSelect,
                                     search,
                                     onSearchChange,
                                     filter,
                                     onFilterChange,
                                     hasMore,
                                     isLoadingMore,
                                     onLoadMore,
                                 }: ConversationListProps) => {
    const sentinelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const sentinel = sentinelRef.current;
        if (!sentinel || !hasMore || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            if (entries[0]?.isIntersecting && !isLoadingMore) {
                onLoadMore();
            }
        }, {rootMargin: '200px'});

        observer.observe(sentinel);
        return () => observer.disconnect();
    }, [hasMore, isLoadingMore, onLoadMore, conversations.length]);

    return (
        <div className={classes.listColumn}>
            <div className={classes.listFilters}>
                <TextInput
                    value={search}
                    onChange={(event) => onSearchChange(event.currentTarget.value)}
                    placeholder={t`Name or number`}
                    leftSection={<IconSearch size={16}/>}
                />
                <SegmentedControl
                    fullWidth
                    size="xs"
                    value={filter}
                    onChange={(value) => onFilterChange(value as SmsConversationFilter)}
                    data={[
                        {value: 'all', label: t`All`},
                        {value: 'unread', label: t`Unread`},
                        {value: 'needs_human', label: t`To handle`},
                    ]}
                />
            </div>

            <div
                className={classes.listItems}
                onScroll={(event) => {
                    const list = event.currentTarget;
                    if (hasMore && !isLoadingMore && list.scrollHeight - list.scrollTop - list.clientHeight < 200) {
                        onLoadMore();
                    }
                }}
            >
                {isLoading && <div className={classes.centered}><Loader size="sm"/></div>}

                {!isLoading && conversations.length === 0 && (
                    <Text size="sm" c="dimmed" className={classes.centered}>
                        {t`No conversation yet. Replies to your SMS will appear here.`}
                    </Text>
                )}

                {conversations.map(conversation => (
                    <UnstyledButton
                        key={conversation.id}
                        className={classes.listItem}
                        data-active={conversation.id === selectedId || undefined}
                        data-unread={conversation.unread_count > 0 || undefined}
                        onClick={() => onSelect(conversation)}
                    >
                        <div className={classes.listItemTop}>
                            <span className={classes.listItemName}>{conversation.contact_name || conversation.phone}</span>
                            {conversation.last_message_at && (
                                <span className={classes.listItemDate}>{relativeDate(conversation.last_message_at)}</span>
                            )}
                        </div>
                        {conversation.contact_name && <div className={classes.listItemPhone}>{conversation.phone}</div>}
                        <div className={classes.listItemBottom}>
                            <span className={classes.listItemPreview}>
                                {conversation.last_message_direction === 'OUTBOUND' ? `${t`You`}: ` : ''}
                                {conversation.last_message_preview}
                            </span>
                            {conversation.unread_count > 0 && (
                                <Badge size="sm" circle>{conversation.unread_count}</Badge>
                            )}
                        </div>
                        {conversation.status === 'NEEDS_HUMAN' && (
                            <Badge size="xs" color="orange" variant="light" mt={4}>
                                {conversationStatusLabel(conversation.status)}
                            </Badge>
                        )}
                    </UnstyledButton>
                ))}

                {hasMore && (
                    <div ref={sentinelRef} className={classes.centered}>
                        {isLoadingMore && <Loader size="xs"/>}
                    </div>
                )}
            </div>
        </div>
    );
};
