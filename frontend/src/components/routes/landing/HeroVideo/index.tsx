import {useEffect, useRef} from "react";
import {t} from "@lingui/macro";
import {getConfig} from "../../../../utilites/config.ts";
import {getAppName, getLogoForDarkBackground} from "../../../../utilites/branding.ts";
import classes from "./HeroVideo.module.scss";

export const HeroVideo = () => {
    const videoUrl = getConfig("VITE_LANDING_VIDEO_URL");
    const posterUrl = getConfig("VITE_LANDING_VIDEO_POSTER");
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const video = videoRef.current;
        if (!video || !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
            return;
        }
        video.pause();
        video.controls = true;
    }, []);

    return (
        <div className={classes.stage}>
            <div className={classes.frame}>
                {videoUrl ? (
                    <video
                        ref={videoRef}
                        className={classes.video}
                        src={videoUrl}
                        poster={posterUrl || undefined}
                        autoPlay
                        muted
                        loop
                        playsInline
                        preload="metadata"
                        aria-label={t`Motion design video presenting ${getAppName()}: from invitation to printed badge.`}
                    />
                ) : (
                    <div className={classes.placeholder} aria-hidden="true">
                        <img src={getLogoForDarkBackground()} alt="" className={classes.placeholderLogo}/>
                    </div>
                )}
            </div>
        </div>
    );
};
