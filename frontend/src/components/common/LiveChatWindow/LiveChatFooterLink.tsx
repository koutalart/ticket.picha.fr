import {t} from "@lingui/macro";
import {IdParam, Organizer} from "../../../types.ts";
import {useGetLiveChatConfig} from "../../../queries/useGetLiveChatConfig.ts";
import {ASSISTANT_NAME} from "./LiveChatAvatar.tsx";
import {openLiveChat} from "./LiveChatLauncher.tsx";
import {useLiveChatI18n} from "./liveChatI18n.ts";

interface LiveChatFooterLinkProps {
    organizer?: Organizer | null;
    eventId?: IdParam;
    className?: string;
    asListItem?: boolean;
}

/**
 * Discreet footer link that opens the « Messagerie » chat, also when the visitor hid the bubble.
 */
export const LiveChatFooterLink = ({organizer, eventId, className, asListItem = false}: LiveChatFooterLinkProps) => {
    const config = useGetLiveChatConfig(organizer?.id, eventId);
    const chatI18n = useLiveChatI18n(organizer);

    if (!organizer?.id || !config.data?.enabled || config.data.style !== 'MESSAGING') {
        return null;
    }

    const link = (
        <button type="button" className={className} onClick={openLiveChat}>
            {ASSISTANT_NAME
                ? t(chatI18n)`A question? Write to ${ASSISTANT_NAME}`
                : t(chatI18n)`A question? Write to us`}
        </button>
    );

    return asListItem ? <li>{link}</li> : link;
};

export default LiveChatFooterLink;
