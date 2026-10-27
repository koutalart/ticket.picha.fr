import {t} from "@lingui/macro";
import {Badge, Group, Loader, SegmentedControl, Text, TextInput, UnstyledButton} from "@mantine/core";
import {IconSearch} from "@tabler/icons-react";
import {EmailThread, EmailThreadFilter} from "../../../../../types.ts";
import {relativeDate} from "../../../../../utilites/dates.ts";
import {emailStatusLabel, emailThreadStatusLabel, isFailedEmailStatus} from "./emailLabels.ts";
import classes from "../Sms.module.scss";

interface EmailThreadListProps {
    threads: EmailThread[];
    isLoading: boolean;
    selectedId: number | null;
    onSelect: (thread: EmailThread) => void;
    search: string;
    onSearchChange: (value: string) => void;
    filter: EmailThreadFilter;
    onFilterChange: (value: EmailThreadFilter) => void;
    hasMore: boolean;
    isLoadingMore: boolean;
    onLoadMore: () => void;
}

export const EmailThreadList = ({
                                    threads,
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
                                }: EmailThreadListProps) => {
    return (
        <div className={classes.listColumn}>
            <div className={classes.listFilters}>
                <TextInput
                    value={search}
                    onChange={(event) => onSearchChange(event.currentTarget.value)}
                    placeholder={t`Name, e-mail or subject`}
                    leftSection={<IconSearch size={16}/>}
                />
                <SegmentedControl
                    fullWidth
                    size="xs"
                    value={filter}
                    onChange={(value) => onFilterChange(value as EmailThreadFilter)}
                    data={[
                        {value: 'all', label: t`All`},
                        {value: 'unread', label: t`Unread`},
                        {value: 'needs_human', label: t`To handle`},
                        {value: 'failed', label: t`Not delivered`},
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

                {!isLoading && threads.length === 0 && (
                    <Text size="sm" c="dimmed" className={classes.centered}>
                        {t`No e-mail yet. The e-mails sent to your buyers and their replies will appear here.`}
                    </Text>
                )}

                {threads.map(thread => {
                    const failed = isFailedEmailStatus(thread.last_message_status);

                    return (
                        <UnstyledButton
                            key={thread.id}
                            className={classes.listItem}
                            data-active={thread.id === selectedId || undefined}
                            data-unread={thread.unread_count > 0 || undefined}
                            onClick={() => onSelect(thread)}
                        >
                            <div className={classes.listItemTop}>
                                <span className={classes.listItemName}>{thread.contact_name || thread.email}</span>
                                {thread.last_message_at && (
                                    <span className={classes.listItemDate}>{relativeDate(thread.last_message_at)}</span>
                                )}
                            </div>
                            {thread.contact_name && <div className={classes.listItemPhone}>{thread.email}</div>}
                            <div className={classes.listItemBottom}>
                                <span className={classes.listItemPreview}>
                                    {thread.last_message_direction === 'OUTBOUND' ? `${t`You`}: ` : ''}
                                    {thread.last_message_subject || thread.last_message_preview}
                                </span>
                                {thread.unread_count > 0 && <Badge size="sm" circle>{thread.unread_count}</Badge>}
                            </div>
                            {(thread.status === 'NEEDS_HUMAN' || failed) && (
                                <Group gap={4} mt={4}>
                                    {thread.status === 'NEEDS_HUMAN' && (
                                        <Badge size="xs" color="orange" variant="light">{emailThreadStatusLabel(thread.status)}</Badge>
                                    )}
                                    {failed && thread.last_message_status && (
                                        <Badge size="xs" color="red" variant="light">{emailStatusLabel(thread.last_message_status)}</Badge>
                                    )}
                                </Group>
                            )}
                        </UnstyledButton>
                    );
                })}

                {hasMore && (
                    <div className={classes.centered}>
                        {isLoadingMore ? <Loader size="xs"/> : (
                            <UnstyledButton onClick={onLoadMore}><Text size="sm" c="dimmed">{t`Load more`}</Text></UnstyledButton>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};
