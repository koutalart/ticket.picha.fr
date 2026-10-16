const YOUTUBE_URL = /^https?:\/\/(?:www\.|m\.|music\.)?(?:youtube\.com\/(?:watch\?(?:[^"#]*&)?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{11})/i;
const PARAGRAPH = /<p\b[^>]*>[\s\S]*?<\/p>/gi;
const HREF = /href\s*=\s*"([^"]+)"/gi;

export type DescriptionPart = { html: string } | { videoId: string };

export const youtubeVideoId = (url: string): string | null => {
    const match = url.replace(/&amp;/g, '&').match(YOUTUBE_URL);
    return match ? match[1] : null;
};

export const splitDescriptionWithVideos = (html: string): DescriptionPart[] => {
    const parts: DescriptionPart[] = [];
    let lastIndex = 0;

    for (const paragraph of html.matchAll(PARAGRAPH)) {
        const videoIds = [...paragraph[0].matchAll(HREF)]
            .map((href) => youtubeVideoId(href[1]))
            .filter((id): id is string => id !== null);

        if (videoIds.length === 0) {
            continue;
        }

        const end = (paragraph.index ?? 0) + paragraph[0].length;
        parts.push({html: html.slice(lastIndex, end)});
        [...new Set(videoIds)].forEach((videoId) => parts.push({videoId}));
        lastIndex = end;
    }

    if (lastIndex < html.length) {
        parts.push({html: html.slice(lastIndex)});
    }

    return parts;
};
