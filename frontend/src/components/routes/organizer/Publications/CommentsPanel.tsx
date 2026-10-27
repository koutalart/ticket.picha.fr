import {useMemo, useState} from "react";
import {t} from "@lingui/macro";
import {Badge, Button, Group, Loader, Text, Textarea} from "@mantine/core";
import {IconCornerDownRight, IconSend, IconSparkles} from "@tabler/icons-react";
import {IdParam, SocialComment} from "../../../../types.ts";
import {useGetSocialComments} from "../../../../queries/useGetSocialComments.ts";
import {useSuggestSocialCommentReply} from "../../../../mutations/useSuggestSocialCommentReply.ts";
import {useReplyToSocialComment} from "../../../../mutations/useReplyToSocialComment.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {relativeDate} from "../../../../utilites/dates.ts";
import classes from "./Publications.module.scss";

interface CommentsPanelProps {
    organizerId: IdParam;
    feedItemId: number;
    canReply: boolean;
}

interface Thread {
    comment: SocialComment;
    replies: SocialComment[];
}

const MAX_REPLY_LENGTH = 2200;

const ReplyComposer = ({organizerId, comment, onDone}: { organizerId: IdParam, comment: SocialComment, onDone: () => void }) => {
    const [message, setMessage] = useState('');
    const suggestMutation = useSuggestSocialCommentReply();
    const replyMutation = useReplyToSocialComment();

    const suggest = () => {
        suggestMutation.mutate({organizerId, commentId: comment.id}, {
            onSuccess: (suggestion) => setMessage(suggestion),
            onError: (error: any) => showError(error?.response?.data?.message ?? t`Anicka could not suggest a reply. Please try again or write it yourself.`),
        });
    };

    const send = () => {
        replyMutation.mutate({organizerId, commentId: comment.id, message: message.trim()}, {
            onSuccess: () => {
                showSuccess(t`Reply sent`);
                onDone();
            },
            onError: (error: any) => showError(error?.response?.data?.message ?? t`The reply was not sent`),
        });
    };

    return (
        <div className={classes.replyComposer}>
            <Textarea
                value={message}
                onChange={(event) => setMessage(event.currentTarget.value)}
                placeholder={t`Write your reply, or let Anicka suggest one`}
                autosize
                minRows={2}
                maxRows={6}
                maxLength={MAX_REPLY_LENGTH}
                autoFocus
            />
            <Group gap="xs" mt="xs" justify="space-between">
                <Button size="xs" variant="light" color="violet" leftSection={<IconSparkles size={14}/>} loading={suggestMutation.isPending} onClick={suggest}>
                    {t`Suggest a reply`}
                </Button>
                <Group gap="xs">
                    <Button size="xs" variant="subtle" onClick={onDone}>{t`Cancel`}</Button>
                    <Button size="xs" leftSection={<IconSend size={14}/>} disabled={message.trim() === ''} loading={replyMutation.isPending} onClick={send}>
                        {t`Send`}
                    </Button>
                </Group>
            </Group>
            <Text size="xs" c="dimmed" mt={4}>{t`Nothing is sent until you click Send.`}</Text>
        </div>
    );
};

const CommentLine = ({comment}: { comment: SocialComment }) => (
    <div className={classes.comment}>
        <Text size="sm" className={classes.caption}>
            <strong>{comment.author_name ?? t`Someone`}</strong>{' '}
            {comment.is_from_page && <Badge size="xs" variant="light" color="violet" mr={4}>{t`You`}</Badge>}
            {comment.message}
        </Text>
        {comment.commented_at && <Text size="xs" c="dimmed">{relativeDate(comment.commented_at)}</Text>}
    </div>
);

export const CommentsPanel = ({organizerId, feedItemId, canReply}: CommentsPanelProps) => {
    const commentsQuery = useGetSocialComments(organizerId, feedItemId);
    const [replyingTo, setReplyingTo] = useState<number | null>(null);

    const threads = useMemo<Thread[]>(() => {
        const comments = commentsQuery.data ?? [];
        const roots = comments.filter(comment => !comment.is_reply);

        return roots.map(comment => ({
            comment,
            replies: comments.filter(reply => reply.is_reply && reply.thread_key === comment.thread_key),
        }));
    }, [commentsQuery.data]);

    if (commentsQuery.isLoading) {
        return <Loader size="sm"/>;
    }

    if (threads.length === 0) {
        return <Text size="sm" c="dimmed">{t`No comment yet.`}</Text>;
    }

    return (
        <div className={classes.comments}>
            {threads.map(({comment, replies}) => (
                <div key={comment.id} className={classes.thread}>
                    <CommentLine comment={comment}/>
                    {replies.map(reply => (
                        <div key={reply.id} className={classes.replyLine}>
                            <IconCornerDownRight size={14} className={classes.replyIcon}/>
                            <CommentLine comment={reply}/>
                        </div>
                    ))}
                    {canReply && !comment.is_from_page && (
                        replyingTo === comment.id
                            ? <ReplyComposer organizerId={organizerId} comment={comment} onDone={() => setReplyingTo(null)}/>
                            : (
                                <button type="button" className={classes.replyButton} onClick={() => setReplyingTo(comment.id)}>
                                    {t`Reply`}
                                </button>
                            )
                    )}
                </div>
            ))}
        </div>
    );
};
