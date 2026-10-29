/* eslint-disable lingui/no-unlocalized-strings */
import {OrganizerSettings} from "../types.ts";

/**
 * Public profile addresses of the organizer (no icons, SSR safe), for structured data and llms.txt.
 * Same base URLs as constants/socialMediaConfig.ts.
 */
const SOCIAL_PROFILE_BASE_URLS: Record<string, { label: string; baseUrl: string }> = {
    facebook: {label: "Facebook", baseUrl: "https://facebook.com/"},
    instagram: {label: "Instagram", baseUrl: "https://instagram.com/"},
    tiktok: {label: "TikTok", baseUrl: "https://tiktok.com/@"},
    youtube: {label: "YouTube", baseUrl: "https://youtube.com/@"},
    twitter: {label: "X", baseUrl: "https://twitter.com/"},
    snapchat: {label: "Snapchat", baseUrl: "https://snapchat.com/add/"},
    linkedin: {label: "LinkedIn", baseUrl: "https://linkedin.com/company/"},
    twitch: {label: "Twitch", baseUrl: "https://twitch.tv/"},
    pinterest: {label: "Pinterest", baseUrl: "https://pinterest.com/"},
    telegram: {label: "Telegram", baseUrl: "https://t.me/"},
    reddit: {label: "Reddit", baseUrl: "https://reddit.com/u/"},
    github: {label: "GitHub", baseUrl: "https://github.com/"},
    weibo: {label: "Weibo", baseUrl: "https://weibo.com/"},
};

export interface OrganizerSocialProfile {
    platform: string;
    label: string;
    url: string;
}

export const getOrganizerSocialProfiles = (
    settings?: Pick<OrganizerSettings, "social_media_handles"> | null,
): OrganizerSocialProfile[] => {
    const handles = settings?.social_media_handles || {};

    return Object.entries(handles).flatMap(([platform, value]) => {
        const config = SOCIAL_PROFILE_BASE_URLS[platform];
        const handle = String(value ?? "").trim();
        if (!config || !handle) {
            return [];
        }
        const url = /^https?:\/\//i.test(handle)
            ? handle
            : config.baseUrl + encodeURI(handle.replace(/^@/, "").replace(/^\/+/, ""));
        return [{platform, label: config.label, url}];
    });
};
