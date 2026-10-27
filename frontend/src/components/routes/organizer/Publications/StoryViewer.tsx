import {useCallback, useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {Modal} from "@mantine/core";
import {IconVolume, IconVolumeOff, IconX} from "@tabler/icons-react";
import {SocialFeedItem} from "../../../../types.ts";
import classes from "./Publications.module.scss";

interface StoryViewerProps {
    stories: SocialFeedItem[];
    index: number;
    onChange: (index: number) => void;
    onClose: () => void;
}

const IMAGE_DURATION_MS = 5000;
const HOLD_DELAY_MS = 200;
const SWIPE_CLOSE_DISTANCE = 80;

/**
 * Stories in full screen, as on Instagram or Snapchat: tap on the right for the next one, on the left for the previous one,
 * hold to pause, swipe down to close.
 */
export const StoryViewer = ({stories, index, onChange, onClose}: StoryViewerProps) => {
    const story = stories[index];
    const media = story?.media[0];
    const isVideo = media?.type === 'VIDEO';
    const videoRef = useRef<HTMLVideoElement>(null);
    const [progress, setProgress] = useState(0);
    const [paused, setPaused] = useState(false);
    const [muted, setMuted] = useState(false);
    const pressStart = useRef<{ time: number, x: number, y: number } | null>(null);
    const holdTimer = useRef<number | null>(null);
    const imageProgress = useRef(0);

    const next = useCallback(() => {
        if (index < stories.length - 1) {
            onChange(index + 1);
        } else {
            onClose();
        }
    }, [index, stories.length, onChange, onClose]);

    const previous = useCallback(() => {
        if (index > 0) {
            onChange(index - 1);
        } else {
            imageProgress.current = 0;
            setProgress(0);
            const video = videoRef.current;
            if (video) {
                video.currentTime = 0;
            }
        }
    }, [index, onChange]);

    useEffect(() => {
        imageProgress.current = 0;
        setProgress(0);
        setPaused(false);
    }, [index]);

    useEffect(() => {
        if (isVideo || paused || !story) {
            return;
        }

        let frame = 0;
        let last = performance.now();
        const tick = (now: number) => {
            imageProgress.current = Math.min(1, imageProgress.current + (now - last) / IMAGE_DURATION_MS);
            last = now;
            setProgress(imageProgress.current);
            if (imageProgress.current >= 1) {
                next();
                return;
            }
            frame = window.requestAnimationFrame(tick);
        };
        frame = window.requestAnimationFrame(tick);

        return () => window.cancelAnimationFrame(frame);
    }, [isVideo, paused, story, next]);

    useEffect(() => {
        const video = videoRef.current;
        if (!video || !isVideo) {
            return;
        }
        if (paused) {
            video.pause();
            return;
        }
        video.play().catch(() => {
            video.muted = true;
            setMuted(true);
            video.play().catch(() => undefined);
        });
    }, [paused, isVideo, index]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'ArrowRight') {
                next();
            }
            if (event.key === 'ArrowLeft') {
                previous();
            }
            if (event.key === ' ') {
                event.preventDefault();
                setPaused(value => !value);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [next, previous]);

    if (!story || !media) {
        return null;
    }

    const clearHold = () => {
        if (holdTimer.current !== null) {
            window.clearTimeout(holdTimer.current);
            holdTimer.current = null;
        }
    };

    const onPressStart = (x: number, y: number) => {
        pressStart.current = {time: Date.now(), x, y};
        clearHold();
        holdTimer.current = window.setTimeout(() => setPaused(true), HOLD_DELAY_MS);
    };

    const onPressEnd = (x: number, y: number, width: number, left: number) => {
        clearHold();
        const start = pressStart.current;
        pressStart.current = null;

        if (!start) {
            return;
        }
        if (y - start.y > SWIPE_CLOSE_DISTANCE) {
            onClose();
            return;
        }
        if (Date.now() - start.time >= HOLD_DELAY_MS) {
            setPaused(false);
            return;
        }
        if (x - left < width / 3) {
            previous();
        } else {
            next();
        }
    };

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
            <div className={classes.storyViewer}>
                <div
                    className={classes.storyStage}
                    onMouseDown={(event) => onPressStart(event.clientX, event.clientY)}
                    onMouseUp={(event) => {
                        const rect = event.currentTarget.getBoundingClientRect();
                        onPressEnd(event.clientX, event.clientY, rect.width, rect.left);
                    }}
                    onTouchStart={(event) => onPressStart(event.touches[0].clientX, event.touches[0].clientY)}
                    onTouchEnd={(event) => {
                        event.preventDefault();
                        const rect = event.currentTarget.getBoundingClientRect();
                        onPressEnd(event.changedTouches[0].clientX, event.changedTouches[0].clientY, rect.width, rect.left);
                    }}
                    onContextMenu={(event) => event.preventDefault()}
                >
                    {isVideo ? (
                        <video
                            key={story.id}
                            ref={videoRef}
                            src={media.url}
                            className={classes.storyMedia}
                            playsInline
                            autoPlay
                            muted={muted}
                            onTimeUpdate={(event) => {
                                const video = event.currentTarget;
                                if (video.duration) {
                                    setProgress(video.currentTime / video.duration);
                                }
                            }}
                            onEnded={next}
                        />
                    ) : (
                        <img key={story.id} src={media.url} alt="" className={classes.storyMedia} draggable={false}/>
                    )}

                    <div className={classes.storyTop}>
                        <div className={classes.storyProgress}>
                            {stories.map((item, position) => (
                                <span key={item.id} className={classes.storyProgressTrack}>
                                    <span
                                        className={classes.storyProgressFill}
                                        style={{width: `${position < index ? 100 : position === index ? progress * 100 : 0}%`}}
                                    />
                                </span>
                            ))}
                        </div>
                        <div className={classes.storyButtons}>
                            {isVideo && (
                                <button
                                    type="button"
                                    className={classes.storyButton}
                                    onMouseDown={(event) => event.stopPropagation()}
                                    onMouseUp={(event) => event.stopPropagation()}
                                    onTouchStart={(event) => event.stopPropagation()}
                                    onTouchEnd={(event) => event.stopPropagation()}
                                    onClick={() => setMuted(value => !value)}
                                    aria-label={muted ? t`Turn the sound on` : t`Mute`}
                                >
                                    {muted ? <IconVolumeOff size={22}/> : <IconVolume size={22}/>}
                                </button>
                            )}
                            <button
                                type="button"
                                className={classes.storyButton}
                                onMouseDown={(event) => event.stopPropagation()}
                                onMouseUp={(event) => event.stopPropagation()}
                                onTouchStart={(event) => event.stopPropagation()}
                                onTouchEnd={(event) => event.stopPropagation()}
                                onClick={onClose}
                                aria-label={t`Close`}
                            >
                                <IconX size={24}/>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    );
};
