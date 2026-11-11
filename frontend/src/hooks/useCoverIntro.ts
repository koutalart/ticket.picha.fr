import {useCallback, useEffect, useRef, useState} from "react";

export const COVER_INTRO_MIN_MS = 1800;
export const COVER_INTRO_FADE_MS = 900;

export type CoverIntroPhase = 'poster' | 'fading' | 'done';

/**
 * Shows the cover image first, once, then fades to the looping video when it is really
 * playing (videos may start on black). The image stays at least COVER_INTRO_MIN_MS.
 */
export const useCoverIntro = (videoSource?: string) => {
    const [phase, setPhase] = useState<CoverIntroPhase>('poster');
    const mountedAt = useRef<number>(0);
    const timers = useRef<number[]>([]);
    const started = useRef(false);

    useEffect(() => {
        mountedAt.current = Date.now();
        const pending = timers.current;
        return () => pending.forEach(id => window.clearTimeout(id));
    }, []);

    const onPlaying = useCallback(() => {
        if (started.current) {
            return;
        }
        started.current = true;
        const wait = Math.max(0, COVER_INTRO_MIN_MS - (Date.now() - mountedAt.current));
        timers.current.push(window.setTimeout(() => {
            setPhase('fading');
            timers.current.push(window.setTimeout(() => setPhase('done'), COVER_INTRO_FADE_MS));
        }, wait));
    }, []);

    return {
        phase: videoSource ? phase : 'poster' as CoverIntroPhase,
        onPlaying,
    };
};
