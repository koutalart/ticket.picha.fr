import {useEffect, useRef} from "react";
import {t} from "@lingui/macro";
import {ActionIcon, Badge, Modal, Text} from "@mantine/core";
import {IconBrandFacebook, IconBrandInstagram, IconChevronDown, IconChevronUp, IconX} from "@tabler/icons-react";
import {SocialConnection, SocialPost} from "../../../../types.ts";
import {PostMedia} from "./PostMedia.tsx";
import {accountName, PostActionButtons, PostActions, timeLabel} from "./PostCard.tsx";
import {postFormatLabel, postStatusColor, postStatusLabel} from "./labels.ts";
import classes from "./Publications.module.scss";

interface PostViewerProps {
    posts: SocialPost[];
    index: number;
    connection?: SocialConnection;
    timezone: string;
    actions: PostActions;
    onChange: (index: number) => void;
    onClose: () => void;
}

const SWIPE_DISTANCE = 60;

export const PostViewer = ({posts, index, connection, timezone, actions, onChange, onClose}: PostViewerProps) => {
    const post = posts[index];
    const touchStart = useRef<number | null>(null);
    const hasPrevious = index > 0;
    const hasNext = index < posts.length - 1;

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.key === 'ArrowDown' || event.key === 'ArrowRight') && hasNext) {
                onChange(index + 1);
            }
            if ((event.key === 'ArrowUp' || event.key === 'ArrowLeft') && hasPrevious) {
                onChange(index - 1);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [index, hasNext, hasPrevious]);

    if (!post) {
        return null;
    }

    return (
        <Modal
            opened
            onClose={onClose}
            fullScreen
            withCloseButton={false}
            padding={0}
            classNames={{content: classes.viewerContent, body: classes.viewerBody}}
            transitionProps={{transition: 'fade', duration: 150}}
        >
            <div className={classes.viewer}>
                <ActionIcon className={classes.viewerClose} variant="filled" color="dark" radius="xl" size="lg" onClick={onClose} aria-label={t`Close`}>
                    <IconX size={20}/>
                </ActionIcon>

                <div
                    className={classes.viewerStage}
                    key={post.id}
                    onTouchStart={(event) => {
                        touchStart.current = event.touches[0].clientY;
                    }}
                    onTouchEnd={(event) => {
                        if (touchStart.current === null) {
                            return;
                        }
                        const distance = touchStart.current - event.changedTouches[0].clientY;
                        if (distance > SWIPE_DISTANCE && hasNext) {
                            onChange(index + 1);
                        }
                        if (distance < -SWIPE_DISTANCE && hasPrevious) {
                            onChange(index - 1);
                        }
                        touchStart.current = null;
                    }}
                >
                    <PostMedia post={post} fullscreen/>
                </div>

                <aside className={classes.viewerPanel}>
                    <div className={classes.cardHeader}>
                        <span className={post.network === 'INSTAGRAM' ? classes.avatarInstagram : classes.avatarFacebook}>
                            {post.network === 'INSTAGRAM' ? <IconBrandInstagram size={18}/> : <IconBrandFacebook size={18}/>}
                        </span>
                        <div className={classes.cardHeaderText}>
                            <Text size="sm" fw={600} truncate>{accountName(post, connection)}</Text>
                            <Text size="xs" c="dimmed">{timeLabel(post, timezone)}</Text>
                        </div>
                    </div>
                    <div className={classes.cardBadges}>
                        <Badge variant="light" color="gray">{postFormatLabel(post.format)}</Badge>
                        <Badge variant="light" color={postStatusColor(post.status)}>{postStatusLabel(post.status)}</Badge>
                        {post.source === 'ASSISTANT' && <Badge variant="outline" color="violet">{t`Prepared by the assistant`}</Badge>}
                    </div>
                    {post.caption && <Text size="sm" className={classes.caption}>{post.caption}</Text>}
                    {post.link && <Text size="sm" c="dimmed" style={{wordBreak: 'break-all'}}>{post.link}</Text>}
                    {post.last_error && post.status !== 'PUBLISHED' && (
                        <Text size="xs" c={post.status === 'FAILED' ? 'red' : 'orange'}>{post.last_error}</Text>
                    )}
                    <PostActionButtons post={post} actions={actions}/>
                </aside>

                <div className={classes.viewerNav}>
                    <ActionIcon variant="filled" color="dark" radius="xl" size="lg" disabled={!hasPrevious} onClick={() => onChange(index - 1)} aria-label={t`Previous post`}>
                        <IconChevronUp size={20}/>
                    </ActionIcon>
                    <ActionIcon variant="filled" color="dark" radius="xl" size="lg" disabled={!hasNext} onClick={() => onChange(index + 1)} aria-label={t`Next post`}>
                        <IconChevronDown size={20}/>
                    </ActionIcon>
                </div>
            </div>
        </Modal>
    );
};
