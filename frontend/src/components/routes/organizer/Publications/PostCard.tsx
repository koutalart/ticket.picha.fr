import {t} from "@lingui/macro";
import {Badge, Button, Text} from "@mantine/core";
import {IconBrandFacebook, IconBrandInstagram, IconCheck, IconExternalLink, IconHeart, IconMessageCircle, IconTrash, IconX} from "@tabler/icons-react";
import {SocialConnection, SocialPost, SocialPostStatus} from "../../../../types.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {PostMedia} from "./PostMedia.tsx";
import {accountName, FeedView, timeLabel} from "./feedView.ts";
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

export const NetworkAvatar = ({view}: { view: FeedView }) => (
    <span className={view.network === 'INSTAGRAM' ? classes.avatarInstagram : classes.avatarFacebook}>
        {view.network === 'INSTAGRAM' ? <IconBrandInstagram size={18}/> : <IconBrandFacebook size={18}/>}
    </span>
);

export const ViewBadges = ({view}: { view: FeedView }) => (
    <div className={classes.cardBadges}>
        <Badge variant="light" color="gray">{postFormatLabel(view.format)}</Badge>
        {view.status && view.status !== 'PUBLISHED' && (
            <Badge variant="light" color={postStatusColor(view.status)}>{postStatusLabel(view.status)}</Badge>
        )}
        {view.status === 'PUBLISHED' && view.fromPicha && <Badge variant="light" color="green">{postStatusLabel('PUBLISHED')}</Badge>}
    </div>
);

export const SourceBadges = ({view}: { view: FeedView }) => (
    <div className={classes.sourceBadges}>
        {view.fromPicha
            ? <Badge variant="dot" color="violet" size="sm">{t`PICHA`}</Badge>
            : <Badge variant="dot" color="gray" size="sm">{view.network === 'INSTAGRAM' ? t`Published directly on Instagram` : t`Published directly on Facebook`}</Badge>}
        {view.fromAssistant && <Badge variant="outline" color="violet" size="sm">{t`Prepared by the assistant`}</Badge>}
    </div>
);

export const PostActionButtons = ({view, actions}: { view: FeedView, actions: PostActions }) => {
    const post = view.post;

    return (
        <div className={classes.actions}>
            {post && APPROVABLE.includes(post.status) && (
                <Button
                    size="sm"
                    leftSection={<IconCheck size={16}/>}
                    disabled={!actions.canPublish}
                    loading={actions.pendingPostId === post.id}
                    onClick={() => actions.approve(post)}
                >
                    {post.status === 'FAILED' ? t`Retry` : t`Approve`}
                </Button>
            )}
            {post && CANCELLABLE.includes(post.status) && (
                <Button size="sm" variant="default" leftSection={<IconX size={16}/>} onClick={() => actions.cancel(post)}>
                    {t`Cancel`}
                </Button>
            )}
            {view.permalink && (
                <Button component="a" href={view.permalink} target="_blank" rel="noopener noreferrer" size="sm" variant="light" leftSection={<IconExternalLink size={16}/>}>
                    {view.network === 'INSTAGRAM' ? t`View on Instagram` : t`View on Facebook`}
                </Button>
            )}
            {post && DELETABLE.includes(post.status) && (
                <Button size="sm" variant="subtle" color="red" leftSection={<IconTrash size={16}/>} onClick={() => actions.remove(post)}>
                    {t`Delete`}
                </Button>
            )}
        </div>
    );
};

interface PostCardProps {
    view: FeedView;
    connection?: SocialConnection;
    timezone: string;
    actions: PostActions;
    onOpen: () => void;
}

export const PostCard = ({view, connection, timezone, actions, onOpen}: PostCardProps) => (
    <article className={classes.card}>
        <header className={classes.cardHeader}>
            <NetworkAvatar view={view}/>
            <div className={classes.cardHeaderText}>
                <Text size="sm" fw={600} truncate>{accountName(view, connection)}</Text>
                <Text size="xs" c="dimmed">{timeLabel(view, timezone)}</Text>
            </div>
            <ViewBadges view={view}/>
        </header>

        <PostMedia post={view} onOpen={onOpen}/>

        <div className={classes.cardBody}>
            {view.item && (
                <div className={classes.stats}>
                    <span title={t`Likes`}><IconHeart size={24}/> {formatNumber(view.likeCount ?? 0)}</span>
                    <button type="button" className={classes.statsButton} onClick={onOpen} title={t`Comments`}>
                        <IconMessageCircle size={24}/> {formatNumber(view.commentsCount ?? 0)}
                    </button>
                </div>
            )}
            <SourceBadges view={view}/>
            {view.caption && view.media.length > 0 && (
                <Text size="sm" lineClamp={3} className={classes.caption} onClick={onOpen} style={{cursor: 'pointer'}}>
                    <strong>{accountName(view, connection)}</strong> {view.caption}
                </Text>
            )}
            {view.item && (view.commentsCount ?? 0) > 0 && (
                <button type="button" className={classes.commentsLink} onClick={onOpen}>
                    {t`View the ${formatNumber(view.commentsCount ?? 0)} comments`}
                </button>
            )}
            {view.latestComments.length > 0 && (
                <div className={classes.latestComments} onClick={onOpen}>
                    {view.latestComments.map(comment => (
                        <Text key={comment.id} size="sm" lineClamp={2}>
                            <strong>{comment.author_name || t`Someone`}</strong> {comment.message}
                        </Text>
                    ))}
                </div>
            )}
            {view.lastError && (
                <Text size="xs" c={view.status === 'FAILED' ? 'red' : 'orange'} mt={6}>{view.lastError}</Text>
            )}
            <PostActionButtons view={view} actions={actions}/>
        </div>
    </article>
);
