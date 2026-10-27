import {useEffect, useRef} from "react";
import {t} from "@lingui/macro";
import {Text} from "@mantine/core";
import {SocialFeedItem} from "../../../../types.ts";
import {formatDateWithLocale} from "../../../../utilites/dates.ts";
import classes from "./Publications.module.scss";

interface StoriesBarProps {
    stories: SocialFeedItem[];
    timezone: string;
    onOpen: (index: number) => void;
}

export const StoriesBar = ({stories, timezone, onOpen}: StoriesBarProps) => {
    const trackRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const track = trackRef.current;
        if (track) {
            track.scrollLeft = track.scrollWidth;
        }
    }, [stories.length]);

    if (stories.length === 0) {
        return null;
    }

    return (
        <section className={classes.storiesBar} aria-label={t`Stories`}>
            <Text size="sm" fw={600} mb={6}>{t`Instagram stories`}</Text>
            <div ref={trackRef} className={classes.storiesTrack}>
                {stories.map((story, index) => {
                    const media = story.media[0];

                    return (
                        <button key={story.id} type="button" className={classes.storyBubble} onClick={() => onOpen(index)}>
                            <span className={classes.storyRing}>
                                {media?.type === 'VIDEO'
                                    ? <video src={`${media.url}#t=0.5`} muted playsInline preload="metadata"/>
                                    : <img src={media?.url} alt="" loading="lazy"/>}
                            </span>
                            <span className={classes.storyDate}>
                                {story.published_at ? formatDateWithLocale(story.published_at, 'shortDate', timezone) : ''}
                            </span>
                        </button>
                    );
                })}
            </div>
            <Text size="xs" c="dimmed" mt={4}>{t`Instagram removes stories after 24 hours, PICHA keeps a copy for 90 days.`}</Text>
        </section>
    );
};
