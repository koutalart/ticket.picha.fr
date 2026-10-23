import {useCoverVideoSource} from "../../../hooks/useCoverVideoSource.ts";
import classes from "./EventImmersiveShell.module.scss";

interface EventImmersiveBackdropProps {
    coverVideo?: string;
    coverVideoMobile?: string;
    coverImage?: string;
}

export const EventImmersiveBackdrop = ({coverVideo, coverVideoMobile, coverImage}: EventImmersiveBackdropProps) => {
    const videoSource = useCoverVideoSource(coverVideo, coverVideoMobile);

    return (
        <div className={classes.backdrop} aria-hidden="true">
            {videoSource ? (
                <video src={videoSource} poster={coverImage} autoPlay muted loop playsInline preload="metadata"/>
            ) : coverImage ? (
                <img src={coverImage} alt=""/>
            ) : null}
        </div>
    );
};
