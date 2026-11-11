import {useCoverVideoSource} from "../../../hooks/useCoverVideoSource.ts";
import classes from "./EventImmersiveShell.module.scss";

interface EventImmersiveBackdropProps {
    coverVideo?: string;
    coverVideoMobile?: string;
    coverImage?: string;
}

export const EventImmersiveBackdrop = ({coverVideo, coverVideoMobile, coverImage}: EventImmersiveBackdropProps) => {
    const videoSource = useCoverVideoSource(coverImage ? undefined : coverVideo, coverImage ? undefined : coverVideoMobile);

    return (
        <div className={classes.backdrop} aria-hidden="true">
            {coverImage ? (
                <img src={coverImage} alt="" decoding="async"/>
            ) : videoSource ? (
                <video src={videoSource} autoPlay muted loop playsInline preload="metadata"/>
            ) : null}
        </div>
    );
};
