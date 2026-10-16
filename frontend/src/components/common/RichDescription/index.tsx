import {useState} from "react";
import {t} from "@lingui/macro";
import {IconPlayerPlayFilled} from "@tabler/icons-react";
import {splitDescriptionWithVideos} from "../../../utilites/youtubeEmbeds.ts";
import classes from "./RichDescription.module.scss";

const YoutubePlayer = ({videoId}: { videoId: string }) => {
    const [playing, setPlaying] = useState(false);

    if (playing) {
        return (
            <div className={classes.player}>
                <iframe
                    src={`https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&rel=0`}
                    title={t`YouTube video`}
                    allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                />
            </div>
        );
    }

    return (
        <button type="button" className={classes.player} onClick={() => setPlaying(true)} aria-label={t`Play the video`}>
            <img src={`https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`} alt="" loading="lazy"/>
            <span className={classes.playButton}><IconPlayerPlayFilled size={30}/></span>
        </button>
    );
};

export const RichDescription = ({html, className}: { html: string, className?: string }) => {
    const parts = splitDescriptionWithVideos(html);

    if (parts.length === 1 && 'html' in parts[0]) {
        return <div className={className} dangerouslySetInnerHTML={{__html: html}}/>;
    }

    return (
        <div className={className}>
            {parts.map((part, index) => 'videoId' in part
                ? <YoutubePlayer key={`${part.videoId}-${index}`} videoId={part.videoId}/>
                : <div key={index} dangerouslySetInnerHTML={{__html: part.html}}/>)}
        </div>
    );
};
