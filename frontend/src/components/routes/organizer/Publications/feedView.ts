import {t} from "@lingui/macro";
import {
    SocialComment,
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
    latestComments: SocialComment[];
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
    latestComments: [],
});

export const fromItem = (item: SocialFeedItem, post: SocialPost | null = null, key = `network-${item.id}`): FeedView => ({
    key,
    network: item.network,
    format: item.format,
    caption: item.caption,
    link: null,
    media: item.media,
    status: post ? post.status : 'PUBLISHED',
    fromAssistant: post?.source === 'ASSISTANT',
    fromPicha: !!post,
    permalink: item.permalink,
    lastError: null,
    likeCount: item.like_count,
    commentsCount: item.comments_count,
    post,
    item,
    latestComments: [],
});

export const fromEntry = (entry: SocialFeedEntry): FeedView => {
    if (!entry.item) {
        return fromPost(entry.post!);
    }

    return {
        ...fromItem(entry.item, entry.post, entry.key),
        latestComments: entry.latest_comments ?? [],
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
