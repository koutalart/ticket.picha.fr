import {t} from "@lingui/macro";
import {
    SocialConnection,
    SocialFeedEntry,
    SocialFeedItem,
    SocialFeedItemFormat,
    SocialMediaItem,
    SocialNetwork,
    SocialPost,
    SocialPostStatus,
} from "../../../../types.ts";
import {prettyDate} from "../../../../utilites/dates.ts";

/**
 * One card of the feed, whether the post was prepared in PICHA, read on the network, or both.
 */
export interface FeedView {
    key: string;
    network: SocialNetwork;
    format: SocialFeedItemFormat;
    caption: string | null;
    link: string | null;
    media: SocialMediaItem[];
    status: SocialPostStatus | null;
    fromAssistant: boolean;
    fromPicha: boolean;
    permalink: string | null;
    lastError: string | null;
    likeCount: number | null;
    commentsCount: number | null;
    post: SocialPost | null;
    item: SocialFeedItem | null;
}

export const fromPost = (post: SocialPost): FeedView => ({
    key: `picha-${post.id}`,
    network: post.network,
    format: post.format,
    caption: post.caption,
    link: post.link,
    media: post.media,
    status: post.status,
    fromAssistant: post.source === 'ASSISTANT',
    fromPicha: true,
    permalink: post.permalink,
    lastError: post.status !== 'PUBLISHED' ? post.last_error : null,
    likeCount: null,
    commentsCount: null,
    post,
    item: null,
});

export const fromEntry = (entry: SocialFeedEntry): FeedView => {
    if (!entry.item) {
        return fromPost(entry.post!);
    }

    const item = entry.item;

    return {
        key: entry.key,
        network: item.network,
        format: item.format,
        caption: item.caption,
        link: null,
        media: item.media,
        status: entry.post ? entry.post.status : 'PUBLISHED',
        fromAssistant: entry.post?.source === 'ASSISTANT',
        fromPicha: !!entry.post,
        permalink: item.permalink,
        lastError: null,
        likeCount: item.like_count,
        commentsCount: item.comments_count,
        post: entry.post,
        item,
    };
};

export const accountName = (view: FeedView, connection?: SocialConnection): string => {
    if (view.network === 'INSTAGRAM' && connection?.instagram_username) {
        return connection.instagram_username;
    }

    return connection?.facebook_page_name || (view.network === 'INSTAGRAM' ? 'Instagram' : 'Facebook');
};

export const timeLabel = (view: FeedView, timezone: string): string => {
    const publishedAt = view.item?.published_at ?? view.post?.published_at;

    if (publishedAt) {
        return t`Published on ${prettyDate(publishedAt, timezone)}`;
    }
    if (view.post?.scheduled_at) {
        return t`Planned for ${prettyDate(view.post.scheduled_at, timezone)}`;
    }
    return t`As soon as approved`;
};

export const isVertical = (view: FeedView): boolean => view.format === 'REEL' || view.format === 'STORY';
