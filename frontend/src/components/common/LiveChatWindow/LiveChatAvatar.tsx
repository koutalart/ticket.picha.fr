import {useState} from "react";
import classes from "./LiveChatWindow.module.scss";

export const ASSISTANT_NAME = 'Anicka';

interface LiveChatAvatarProps {
    url?: string | null;
    size?: number;
    className?: string;
}

/**
 * Round photo of the assistant, or her initial on a coloured circle when the organizer has not added a photo.
 */
export const LiveChatAvatar = ({url, size = 40, className}: LiveChatAvatarProps) => {
    const [failed, setFailed] = useState(false);
    const style = {width: size, height: size, fontSize: Math.round(size * 0.45)};

    if (url && !failed) {
        return (
            <img
                src={url}
                alt=""
                className={`${classes.avatar} ${className ?? ''}`}
                style={style}
                onError={() => setFailed(true)}
                loading="lazy"
                decoding="async"
            />
        );
    }

    return (
        <span className={`${classes.avatar} ${classes.avatarFallback} ${className ?? ''}`} style={style} aria-hidden="true">
            {ASSISTANT_NAME.charAt(0)}
        </span>
    );
};
