import {useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IdParam, Organizer} from "../../../types.ts";
import {useGetLiveChatConfig} from "../../../queries/useGetLiveChatConfig.ts";
import {ASSISTANT_NAME, LiveChatAvatar} from "./LiveChatAvatar.tsx";
import {LiveChatWindow} from "./index.tsx";
import classes from "./LiveChatLauncher.module.scss";

interface LiveChatLauncherProps {
    organizer?: Organizer | null;
    eventId?: IdParam;
    aboveBuyBar?: boolean;
}

/**
 * Round button at the bottom right of the public pages that opens the « Messagerie » chat. Only shown when the
 * organizer chose that style; the « Classique » chat stays behind the Contact buttons.
 */
export const LiveChatLauncher = ({organizer, eventId, aboveBuyBar = false}: LiveChatLauncherProps) => {
    const config = useGetLiveChatConfig(organizer?.id);
    const [mounted, setMounted] = useState(false);
    const [opened, setOpened] = useState(false);
    const buttonRef = useRef<HTMLButtonElement>(null);

    if (!organizer?.id || !config.data?.enabled || config.data.style !== 'MESSAGING') {
        return null;
    }

    const open = () => {
        setMounted(true);
        setOpened(true);
    };

    const close = () => {
        setOpened(false);
        window.setTimeout(() => buttonRef.current?.focus(), 0);
    };

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                className={classes.launcher}
                data-above-buy-bar={aboveBuyBar || undefined}
                data-hidden={opened || undefined}
                onClick={open}
                aria-haspopup="dialog"
                aria-expanded={opened}
                aria-label={t`Chat with ${ASSISTANT_NAME}, virtual assistant, online`}
                title={ASSISTANT_NAME}
            >
                <LiveChatAvatar url={config.data.avatar_url} size={60} className={classes.avatar}/>
                <span className={classes.onlineDot} aria-hidden="true"/>
            </button>
            {mounted && (
                <LiveChatWindow
                    opened={opened}
                    onClose={close}
                    organizer={organizer}
                    eventId={eventId}
                    config={config.data}
                />
            )}
        </>
    );
};

export default LiveChatLauncher;
