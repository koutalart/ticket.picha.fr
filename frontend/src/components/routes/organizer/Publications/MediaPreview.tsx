import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconPhotoOff, IconVolume, IconVolumeOff} from "@tabler/icons-react";
import {SocialMediaItem} from "../../../../types.ts";
import classes from "./Publications.module.scss";

interface MediaPreviewProps {
    item: SocialMediaItem;
    autoPlay?: boolean;
    controls?: boolean;
}

const VISIBLE_RATIO = 0.6;

export const MediaPreview = ({item, autoPlay = true, controls = false}: MediaPreviewProps) => {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [failed, setFailed] = useState(false);
    const [muted, setMuted] = useState(true);

    useEffect(() => {
        const video = videoRef.current;
        if (!video || !autoPlay || controls || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(([entry]) => {
            if (entry.intersectionRatio >= VISIBLE_RATIO) {
                video.play().catch(() => undefined);
            } else {
                video.pause();
            }
        }, {threshold: [0, VISIBLE_RATIO, 1]});

        observer.observe(video);

        return () => observer.disconnect();
    }, [autoPlay, controls, item.url]);

    if (failed) {
        return (
            <div className={classes.mediaFallback}>
                <IconPhotoOff size={28} stroke={1.5}/>
                <span>{t`Preview unavailable`}</span>
            </div>
        );
    }

    if (item.type === 'VIDEO') {
        return (
            <>
                <video
                    ref={videoRef}
                    className={classes.mediaElement}
                    src={item.url}
                    poster={item.thumbnail_url ?? undefined}
                    muted={controls ? false : muted}
                    loop
                    playsInline
                    preload="metadata"
                    controls={controls}
                    autoPlay={controls}
                    onError={() => setFailed(true)}
                />
                {!controls && (
                    <button
                        type="button"
                        className={classes.muteButton}
                        onClick={(event) => {
                            event.stopPropagation();
                            setMuted(value => !value);
                        }}
                        aria-label={muted ? t`Turn the sound on` : t`Turn the sound off`}
                    >
                        {muted ? <IconVolumeOff size={16}/> : <IconVolume size={16}/>}
                    </button>
                )}
            </>
        );
    }

    return <img className={classes.mediaElement} src={item.url} alt="" loading="lazy" onError={() => setFailed(true)}/>;
};
