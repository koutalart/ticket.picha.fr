import {t} from "@lingui/macro";
import {Badge, Button, Text} from "@mantine/core";
import {IconBrandFacebook, IconBrandInstagram, IconCheck, IconExternalLink, IconTrash, IconX} from "@tabler/icons-react";
import {SocialConnection, SocialPost, SocialPostStatus} from "../../../../types.ts";
import {prettyDate} from "../../../../utilites/dates.ts";
import {PostMedia} from "./PostMedia.tsx";
import {postFormatLabel, postStatusColor, postStatusLabel} from "./labels.ts";
import classes from "./Publications.module.scss";

export const APPROVABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'FAILED', 'CANCELLED'];
export const CANCELLABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'SCHEDULED'];
export const DELETABLE: SocialPostStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'FAILED', 'CANCELLED'];

export interface PostActions {
    canPublish: boolean;
    approve: (post: SocialPost) => void;
    cancel: (post: SocialPost) => void;
    remove: (post: SocialPost) => void;
    pendingPostId?: number;
}

export const accountName = (post: SocialPost, connection?: SocialConnection): string => {
    if (post.network === 'INSTAGRAM' && connection?.instagram_username) {
        return connection.instagram_username;
    }

    return connection?.facebook_page_name || (post.network === 'INSTAGRAM' ? 'Instagram' : 'Facebook');
};

export const timeLabel = (post: SocialPost, timezone: string): string => {
    if (post.published_at) {
        return t`Published on ${prettyDate(post.published_at, timezone)}`;
    }
    if (post.scheduled_at) {
        return t`Planned for ${prettyDate(post.scheduled_at, timezone)}`;
    }
    return t`As soon as approved`;
};

export const PostActionButtons = ({post, actions, fullWidth = false}: { post: SocialPost, actions: PostActions, fullWidth?: boolean }) => (
    <div className={classes.actions}>
        {APPROVABLE.includes(post.status) && (
            <Button
                size="sm"
                fullWidth={fullWidth}
                leftSection={<IconCheck size={16}/>}
                disabled={!actions.canPublish}
                loading={actions.pendingPostId === post.id}
                onClick={() => actions.approve(post)}
            >
                {post.status === 'FAILED' ? t`Retry` : t`Approve`}
            </Button>
        )}
        {CANCELLABLE.includes(post.status) && (
            <Button size="sm" variant="default" leftSection={<IconX size={16}/>} onClick={() => actions.cancel(post)}>
                {t`Cancel`}
            </Button>
        )}
        {post.permalink && (
            <Button component="a" href={post.permalink} target="_blank" rel="noopener noreferrer" size="sm" variant="light" leftSection={<IconExternalLink size={16}/>}>
                {post.network === 'INSTAGRAM' ? t`View on Instagram` : t`View on Facebook`}
            </Button>
        )}
        {DELETABLE.includes(post.status) && (
            <Button size="sm" variant="subtle" color="red" leftSection={<IconTrash size={16}/>} onClick={() => actions.remove(post)}>
                {t`Delete`}
            </Button>
        )}
    </div>
);

interface PostCardProps {
    post: SocialPost;
    connection?: SocialConnection;
    timezone: string;
    actions: PostActions;
    onOpen: () => void;
}

export const PostCard = ({post, connection, timezone, actions, onOpen}: PostCardProps) => (
    <article className={classes.card}>
        <header className={classes.cardHeader}>
            <span className={post.network === 'INSTAGRAM' ? classes.avatarInstagram : classes.avatarFacebook}>
                {post.network === 'INSTAGRAM' ? <IconBrandInstagram size={18}/> : <IconBrandFacebook size={18}/>}
            </span>
            <div className={classes.cardHeaderText}>
                <Text size="sm" fw={600} truncate>{accountName(post, connection)}</Text>
                <Text size="xs" c="dimmed">{timeLabel(post, timezone)}</Text>
            </div>
            <div className={classes.cardBadges}>
                <Badge variant="light" color="gray">{postFormatLabel(post.format)}</Badge>
                <Badge variant="light" color={postStatusColor(post.status)}>{postStatusLabel(post.status)}</Badge>
            </div>
        </header>

        <PostMedia post={post} onOpen={onOpen}/>

        <div className={classes.cardBody}>
            {post.source === 'ASSISTANT' && (
                <Badge variant="outline" color="violet" size="sm" mb={8}>{t`Prepared by the assistant`}</Badge>
            )}
            {post.caption && post.media.length > 0 && (
                <Text size="sm" lineClamp={3} className={classes.caption} onClick={onOpen} style={{cursor: 'pointer'}}>
                    <strong>{accountName(post, connection)}</strong> {post.caption}
                </Text>
            )}
            {post.last_error && post.status !== 'PUBLISHED' && (
                <Text size="xs" c={post.status === 'FAILED' ? 'red' : 'orange'} mt={6}>{post.last_error}</Text>
            )}
            <PostActionButtons post={post} actions={actions}/>
        </div>
    </article>
);
