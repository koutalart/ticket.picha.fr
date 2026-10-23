import {useEffect, useState} from "react";
import {useDeferredCoverVideo} from "./useDeferredCoverVideo.ts";

export const MOBILE_COVER_VIDEO_MEDIA_QUERY = '(max-width: 767.98px)';

export const selectCoverVideoSource = (
    videoUrl: string | null | undefined,
    mobileVideoUrl: string | null | undefined,
    isNarrowScreen: boolean,
): string | undefined => {
    if (!videoUrl) {
        return undefined;
    }

    return isNarrowScreen && mobileVideoUrl ? mobileVideoUrl : videoUrl;
};

/**
 * Returns the cover video to play, or undefined while it must not load (server render,
 * page still loading, slow connection). The screen width is read once after mounting, so
 * a single video element is rendered and only one file is ever downloaded.
 */
export const useCoverVideoSource = (
    videoUrl?: string | null,
    mobileVideoUrl?: string | null,
): string | undefined => {
    const allowed = useDeferredCoverVideo(videoUrl);
    const [source, setSource] = useState<string | undefined>(undefined);

    useEffect(() => {
        if (!allowed || !videoUrl) {
            setSource(undefined);
            return;
        }

        const isNarrowScreen = typeof window.matchMedia === 'function'
            && window.matchMedia(MOBILE_COVER_VIDEO_MEDIA_QUERY).matches;
        setSource(selectCoverVideoSource(videoUrl, mobileVideoUrl, isNarrowScreen));
    }, [allowed, videoUrl, mobileVideoUrl]);

    return source;
};
