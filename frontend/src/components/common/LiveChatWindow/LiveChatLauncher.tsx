import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {IconX} from "@tabler/icons-react";
import {Event, IdParam, Organizer} from "../../../types.ts";
import {useGetLiveChatConfig} from "../../../queries/useGetLiveChatConfig.ts";
import {ASSISTANT_NAME, LiveChatAvatar} from "./LiveChatAvatar.tsx";
import {LiveChatWindow} from "./index.tsx";
import {useLiveChatI18n} from "./liveChatI18n.ts";
import classes from "./LiveChatLauncher.module.scss";

export const OPEN_LIVE_CHAT_EVENT = 'picha:open-live-chat';

const HIDDEN_STORAGE_KEY = 'picha-live-chat-hidden';

const readHidden = (): boolean => {
    try {
        return window.sessionStorage.getItem(HIDDEN_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
};

const storeHidden = (hidden: boolean) => {
    try {
        if (hidden) {
            window.sessionStorage.setItem(HIDDEN_STORAGE_KEY, '1');
        } else {
            window.sessionStorage.removeItem(HIDDEN_STORAGE_KEY);
        }
    } catch {
        // Private browsing: the bubble simply comes back on the next page.
    }
};

/**
 * Opens the « Messagerie » chat from anywhere on the page (a footer link) and shows the bubble again.
 */
export const openLiveChat = () => {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent(OPEN_LIVE_CHAT_EVENT));
    }
};

interface LiveChatLauncherProps {
    organizer?: Organizer | null;
    eventId?: IdParam;
    event?: Event | null;
    onBook?: () => void;
}

/**
 * Round button at the bottom right of the public pages that opens the « Messagerie » chat. Only shown when the
 * organizer chose that style; the « Classique » chat stays behind the Contact buttons. It sits above a sticky buy
 * bar, can be hidden for the session and comes back from the « Une question ? » footer link.
 */
export const LiveChatLauncher = ({organizer, eventId, event, onBook}: LiveChatLauncherProps) => {
    const config = useGetLiveChatConfig(organizer?.id);
    const chatI18n = useLiveChatI18n(organizer);
    const [mounted, setMounted] = useState(false);
    const [opened, setOpened] = useState(false);
    const [hidden, setHidden] = useState(true);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const available = !!organizer?.id && !!config.data?.enabled && config.data.style === 'MESSAGING';

    useEffect(() => {
        setHidden(readHidden());
    }, []);

    useEffect(() => {
        if (!available) {
            return;
        }
        const openFromLink = () => {
            storeHidden(false);
            setHidden(false);
            setMounted(true);
            setOpened(true);
        };
        window.addEventListener(OPEN_LIVE_CHAT_EVENT, openFromLink);
        return () => window.removeEventListener(OPEN_LIVE_CHAT_EVENT, openFromLink);
    }, [available]);

    if (!available || !config.data || !organizer) {
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

    const hide = () => {
        storeHidden(true);
        setHidden(true);
    };

    return (
        <>
            {!hidden && (
                <div className={classes.wrap} data-hidden={opened || undefined}>
                    <button
                        ref={buttonRef}
                        type="button"
                        className={classes.launcher}
                        onClick={open}
                        aria-haspopup="dialog"
                        aria-expanded={opened}
                        aria-label={t(chatI18n)`Chat with ${ASSISTANT_NAME}, virtual assistant, online`}
                        title={ASSISTANT_NAME}
                    >
                        <LiveChatAvatar url={config.data.avatar_url} size={60} className={classes.avatar}/>
                        <span className={classes.onlineDot} aria-hidden="true"/>
                    </button>
                    <button
                        type="button"
                        className={classes.hide}
                        onClick={hide}
                        aria-label={t(chatI18n)`Hide the assistant`}
                    >
                        <span className={classes.hideIcon} aria-hidden="true"><IconX size={12} stroke={3}/></span>
                    </button>
                </div>
            )}
            {mounted && (
                <LiveChatWindow
                    opened={opened}
                    onClose={close}
                    organizer={organizer}
                    eventId={eventId ?? event?.id}
                    event={event}
                    onBook={onBook}
                    config={config.data}
                />
            )}
        </>
    );
};

export default LiveChatLauncher;
