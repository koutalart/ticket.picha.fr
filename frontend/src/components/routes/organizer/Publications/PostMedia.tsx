import {useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconAlignLeft, IconChevronLeft, IconChevronRight} from "@tabler/icons-react";
import classNames from "classnames";
import {FeedView, isVertical} from "./feedView.ts";
import {MediaPreview} from "./MediaPreview.tsx";
import classes from "./Publications.module.scss";

interface PostMediaProps {
    post: FeedView;
    onOpen?: () => void;
    fullscreen?: boolean;
}

export const PostMedia = ({post, onOpen, fullscreen = false}: PostMediaProps) => {
    const trackRef = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const media = post.media;

    const frameClass = classNames(
        classes.mediaFrame,
        isVertical(post) ? classes.vertical : classes.square,
        fullscreen && classes.fullscreenFrame,
        onOpen && classes.clickable,
    );

    if (media.length === 0) {
        return (
            <div className={classNames(classes.mediaFrame, classes.textOnly, onOpen && classes.clickable)} onClick={onOpen}>
                <IconAlignLeft size={22} stroke={1.5}/>
                <span>{post.caption || post.link || t`Text post`}</span>
            </div>
        );
    }

    if (media.length === 1) {
        return (
            <div className={frameClass} onClick={onOpen}>
                <MediaPreview item={media[0]} controls={fullscreen}/>
            </div>
        );
    }

    const onScroll = () => {
        const track = trackRef.current;
        if (track) {
            setIndex(Math.round(track.scrollLeft / track.clientWidth));
        }
    };

    const goTo = (position: number) => {
        const track = trackRef.current;
        track?.scrollTo({left: position * track.clientWidth, behavior: 'smooth'});
    };

    return (
        <div className={frameClass}>
            <div ref={trackRef} className={classes.carouselTrack} onScroll={onScroll} onClick={onOpen}>
                {media.map((item, position) => (
                    <div key={position} className={classes.carouselSlide}>
                        <MediaPreview item={item} controls={fullscreen}/>
                    </div>
                ))}
            </div>
            {index > 0 && (
                <button type="button" className={classNames(classes.carouselArrow, classes.carouselPrevious)} onClick={() => goTo(index - 1)} aria-label={t`Previous image`}>
                    <IconChevronLeft size={18}/>
                </button>
            )}
            {index < media.length - 1 && (
                <button type="button" className={classNames(classes.carouselArrow, classes.carouselNext)} onClick={() => goTo(index + 1)} aria-label={t`Next image`}>
                    <IconChevronRight size={18}/>
                </button>
            )}
            <span className={classes.carouselCounter}>{index + 1}/{media.length}</span>
            <div className={classes.carouselDots}>
                {media.map((_, position) => (
                    <span key={position} className={classNames(classes.dot, position === index && classes.dotActive)}/>
                ))}
            </div>
        </div>
    );
};
