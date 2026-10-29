import {FormEvent, KeyboardEvent, useEffect, useRef, useState} from "react";
import {ActionIcon, Anchor, Button, Drawer, Text, Textarea, TextInput, VisuallyHidden} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import {useQueryClient} from "@tanstack/react-query";
import {Select, t, Trans} from "@lingui/macro";
import {I18nProvider} from "@lingui/react";
import {IconArrowLeft, IconSend} from "@tabler/icons-react";
import {Event, IdParam, LiveChatConfig, LiveChatMessage, LiveChatTurn, Organizer} from "../../../types.ts";
import {usePostLiveChatMessage, useStartLiveChat, useSubmitLiveChatContact} from "../../../mutations/useLiveChat.ts";
import {liveChatPublicClient} from "../../../api/live-chat.client.ts";
import {
    cacheLiveChatMessages,
    fetchLiveChatMessages,
    forgetLiveChatMessages,
    getCachedLiveChatMessages,
} from "../../../queries/useGetLiveChatMessages.ts";
import {getPrivacyPolicyUrl} from "../../../utilites/branding.ts";
import {ContactOrganizerForm} from "../ContactOrganizerModal/ContactOrganizerForm.tsx";
import {LiveChatRefundCard} from "./LiveChatRefundCard.tsx";
import {ASSISTANT_NAME, LiveChatAvatar} from "./LiveChatAvatar.tsx";
import {LiveChatMessagingScope} from "./LiveChatMessagingScope.tsx";
import {LiveChatEventBar} from "./LiveChatEventBar.tsx";
import {useLiveChatI18n} from "./liveChatI18n.ts";
import {BUY_QUERY_PARAM, scrollToTicketsSection, TICKETS_SECTION_ID} from "../../../utilites/ticketsAnchor.ts";
import {eventHomepagePath} from "../../../utilites/urlHelper.ts";
import classes from "./LiveChatWindow.module.scss";

interface LiveChatWindowProps {
    opened: boolean;
    onClose: () => void;
    organizer: Organizer;
    eventId?: IdParam;
    event?: Event | null;
    onBook?: () => void;
    config: LiveChatConfig;
}

const STORAGE_PREFIX = 'picha-live-chat:';
const CONTACT_DISMISSED_PREFIX = 'picha-live-chat-contact-later:';
const CLOSE_BEFORE_BOOK_MS = 220;
const PHONE_PATTERN = /^\+?[0-9\s().-]{8,20}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const readStoredSession = (organizerId: IdParam): string | null => {
    try {
        return window.sessionStorage.getItem(STORAGE_PREFIX + organizerId);
    } catch {
        return null;
    }
};

const storeSession = (organizerId: IdParam, sessionId: string | null) => {
    try {
        if (sessionId) {
            window.sessionStorage.setItem(STORAGE_PREFIX + organizerId, sessionId);
        } else {
            window.sessionStorage.removeItem(STORAGE_PREFIX + organizerId);
        }
    } catch {
        // Private browsing: the chat simply starts again on the next page.
    }
};

// « Plus tard » on the contact request is remembered for the whole conversation.
const readContactDismissed = (sessionId: string): boolean => {
    try {
        return window.sessionStorage.getItem(CONTACT_DISMISSED_PREFIX + sessionId) === '1';
    } catch {
        return false;
    }
};

const storeContactDismissed = (sessionId: string) => {
    try {
        window.sessionStorage.setItem(CONTACT_DISMISSED_PREFIX + sessionId, '1');
    } catch {
        // Private browsing: the request may come back on the next page.
    }
};

// While the full screen chat is open, the page cannot be zoomed in by a tap in a field (iOS, in-app browsers).
const NO_ZOOM = 'maximum-scale=1';

const statusOf = (error: any): number | undefined => error?.response?.status;

// Messages already shown stay in place: the server copy replaces the known ones and the rest keeps its order.
const mergeMessages = (current: LiveChatMessage[], incoming: LiveChatMessage[]): LiveChatMessage[] => {
    const incomingIds = new Set(incoming.map((message) => message.id));
    const known = [...incoming, ...current.filter((message) => message.id > 0 && !incomingIds.has(message.id))]
        .sort((a, b) => a.id - b.id);
    return [...known, ...current.filter((message) => message.id < 0)];
};

// Above this gap between the layout and the visual viewport, the on-screen keyboard is open.
const KEYBOARD_THRESHOLD = 120;

const between = (min: number, max: number) => min + Math.random() * (max - min);

// Human rhythm: the visitor's message is "read", then each bubble is "typed" at a pace that follows its length.
const readingDelay = () => between(1000, 2000);
const pauseBetweenBubbles = () => between(600, 1200);
const typingDelay = (text: string) => Math.min(6000, Math.max(1500, text.length * between(25, 40)));

// While a question waits for the organizer, the window checks now and then whether the answer arrived.
const ANSWER_POLL_MS = 15000;

const formatTime = (value?: string | null): string => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
};

// French elides « de » before a vowel or a mute h: « Assistante virtuelle d'Innocent Event ».
const startsWithVowel = (name: string) => /^[aeiouyhàâäéèêëîïôöùûüœæ]/i.test(name.trim()) ? 'yes' : 'no';

type TickState = 'sent' | 'delivered' | 'read';

const ReadTicks = ({state}: { state: TickState }) => (
    <svg
        className={`${classes.ticks} ${state === 'read' ? classes.ticksRead : ''}`}
        viewBox="0 0 16 11"
        width="16"
        height="11"
        aria-hidden="true"
        focusable="false"
    >
        {state === 'sent' ? (
            <path d="M4.5 8.6 1.4 5.5l-1 1 4.1 4.1L12 3.1l-1-1z" fill="currentColor"/>
        ) : (
            <path
                d="M11.1 1.6 10.1.6 4.6 6.1l1 1zM14.6.6 8.1 7.1 6.6 5.6l-1 1 2.5 2.5 7.5-7.5zM.4 5.6l1-1 3.5 3.5-1 1z"
                fill="currentColor"
            />
        )}
    </svg>
);

/**
 * « Assistance en direct »: answers the visitor right away with the organizer's assistant. Full screen on a phone,
 * side panel on a desktop. The contact details are only asked when the organizer must follow up.
 */
export const LiveChatWindow = ({opened, onClose, organizer, eventId, event, onBook, config}: LiveChatWindowProps) => {
    const i18n = useLiveChatI18n(organizer);
    const isMobile = useMediaQuery('(max-width: 48em)');
    const messaging = config.style === 'MESSAGING';
    const queryClient = useQueryClient();
    const [openedAt] = useState(() => new Date().toISOString());
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [greeting, setGreeting] = useState<string | null>(null);
    const [messages, setMessages] = useState<LiveChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [askContact, setAskContact] = useState(false);
    const [contactGiven, setContactGiven] = useState(false);
    const [fallback, setFallback] = useState<{ notice: string | null; message: string } | null>(null);
    const [contact, setContact] = useState('');
    const [contactDismissed, setContactDismissed] = useState(false);
    const [contactError, setContactError] = useState<string | null>(null);
    const [startFailed, setStartFailed] = useState(false);
    const [refundOpen, setRefundOpen] = useState(false);
    const [typing, setTyping] = useState(false);
    const [revealing, setRevealing] = useState(false);
    const [pendingQuestion, setPendingQuestion] = useState(false);
    const [restoring, setRestoring] = useState(false);
    const portalRef = useRef<HTMLElement | undefined>(undefined);
    const messagesRef = useRef<LiveChatMessage[]>([]);
    const logRef = useRef<HTMLDivElement>(null);
    const timers = useRef<number[]>([]);
    const revealRun = useRef(0);

    const startMutation = useStartLiveChat();
    const postMutation = usePostLiveChatMessage();
    const contactMutation = useSubmitLiveChatContact();
    const maxLength = config.max_message_length || 800;

    const applyTurnState = (turn: LiveChatTurn) => {
        setPendingQuestion(Boolean(turn.pending_question));
        setAskContact(turn.ask_contact && !turn.contact_given);
        setContactGiven(turn.contact_given);
        if (turn.refund_form) {
            setRefundOpen(true);
        }
    };

    const applyTurn = (turn: LiveChatTurn, replaceAll = false) => {
        setMessages((current) => replaceAll ? mergeMessages(current, turn.messages) : [...current.filter((message) => message.id > 0), ...turn.messages]);
        applyTurnState(turn);
    };

    const wait = (ms: number) => new Promise<void>((resolve) => {
        if (ms <= 0) {
            resolve();
            return;
        }
        timers.current.push(window.setTimeout(resolve, ms));
    });

    const clearTimers = () => {
        timers.current.forEach((id) => window.clearTimeout(id));
        timers.current = [];
    };

    useEffect(() => () => {
        revealRun.current += 1;
        clearTimers();
    }, []);

    /**
     * Shows the answer bubble by bubble: the typing dots appear once the visitor's message has been "read"
     * (typingFrom), each bubble waits for a typing time that follows its length, and a server that answers late
     * adds no extra wait.
     */
    const revealTurn = async (turn: LiveChatTurn, typingFrom: number) => {
        const run = revealRun.current;
        const agentMessages = turn.messages.filter((message) => message.author_type !== 'VISITOR');
        const visitorMessages = turn.messages.filter((message) => message.author_type === 'VISITOR');

        setMessages((current) => [...current.filter((message) => message.id > 0), ...visitorMessages]);
        setRevealing(true);

        let typingStartedAt = typingFrom;
        for (let index = 0; index < agentMessages.length; index++) {
            if (index > 0) {
                setTyping(false);
                await wait(pauseBetweenBubbles());
                typingStartedAt = Date.now();
            } else {
                await wait(typingFrom - Date.now());
            }
            if (run !== revealRun.current) {
                return;
            }
            setTyping(true);
            await wait(typingStartedAt + typingDelay(agentMessages[index].content) - Date.now());
            if (run !== revealRun.current) {
                return;
            }
            const message = agentMessages[index];
            setMessages((current) => [...current, message]);
        }

        setTyping(false);
        setRevealing(false);
        applyTurnState(turn);
    };

    const appendMessages = (newMessages: LiveChatMessage[]) => {
        setMessages((current) => [...current, ...newMessages]);
    };

    const start = () => {
        setStartFailed(false);
        startMutation.mutate({organizerId: organizer.id, eventId}, {
            onSuccess: ({data}) => {
                setSessionId(data.session_id);
                storeSession(organizer.id, data.session_id);
                if (data.greeting) {
                    setGreeting(data.greeting);
                }
                applyTurn(data, true);
            },
            onError: (error: any) => {
                if (statusOf(error) === 429) {
                    setFallback({notice: error?.response?.data?.message ?? null, message: ''});
                    return;
                }
                setStartFailed(true);
            },
        });
    };

    useEffect(() => {
        if (!opened || sessionId || startMutation.isPending) {
            return;
        }

        const stored = readStoredSession(organizer.id);
        if (!stored) {
            start();
            return;
        }

        const cached = getCachedLiveChatMessages(queryClient, stored);
        if (cached) {
            setSessionId(stored);
            applyTurn(cached, true);
        } else {
            setRestoring(true);
        }

        fetchLiveChatMessages(queryClient, stored)
            .then((data) => {
                setSessionId(stored);
                applyTurn(data, true);
            })
            .catch((error) => {
                if (cached && statusOf(error) !== 404) {
                    return;
                }
                forgetLiveChatMessages(queryClient, stored);
                storeSession(organizer.id, null);
                setSessionId(null);
                setMessages([]);
                start();
            })
            .finally(() => setRestoring(false));
    }, [opened]);

    // The conversation is kept in the query cache so that a chat mounted again (another page, the launcher hidden
    // for a while) shows it at once.
    useEffect(() => {
        if (!sessionId) {
            return;
        }
        cacheLiveChatMessages(queryClient, {
            session_id: sessionId,
            greeting: null,
            messages: messages.filter((message) => message.id > 0),
            ask_contact: askContact,
            contact_form_fallback: false,
            contact_given: contactGiven,
            refund_form: false,
            pending_question: pendingQuestion,
        });
    }, [sessionId, messages, askContact, contactGiven, pendingQuestion]);

    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

    useEffect(() => {
        setContactDismissed(sessionId ? readContactDismissed(sessionId) : false);
    }, [sessionId]);

    useEffect(() => {
        if (!messaging || !opened || typeof document === 'undefined') {
            return;
        }
        const meta = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');
        if (!meta) {
            return;
        }
        const original = meta.getAttribute('content') ?? '';
        if (!original.includes('maximum-scale')) {
            meta.setAttribute('content', original ? `${original}, ${NO_ZOOM}` : NO_ZOOM);
        }
        return () => meta.setAttribute('content', original);
    }, [messaging, opened]);

    useEffect(() => {
        if (!opened || !sessionId || !pendingQuestion || revealing || postMutation.isPending) {
            return;
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            liveChatPublicClient.messages(sessionId)
                .then(({data}) => {
                    const lastKnownId = Math.max(0, ...messagesRef.current.map((message) => message.id));
                    const answers = data.messages.filter((message) => message.id > lastKnownId && message.author_type !== 'VISITOR');
                    if (answers.length > 0) {
                        void revealTurn({...data, messages: answers}, Date.now() + pauseBetweenBubbles());
                    } else {
                        setPendingQuestion(Boolean(data.pending_question));
                    }
                })
                .catch(() => undefined);
        }, ANSWER_POLL_MS);

        return () => window.clearInterval(timer);
    }, [opened, sessionId, pendingQuestion, revealing, postMutation.isPending]);

    // On a phone the on-screen keyboard shrinks the visual viewport: the full screen chat follows it so that the
    // input bar stays visible above the keyboard. The size is read once on open, then only when the viewport
    // changes, at most once per frame, and written only when it differs.
    useEffect(() => {
        const target = portalRef.current;
        if (!messaging || !opened || !isMobile || typeof window === 'undefined' || !target) {
            return;
        }
        const viewport = window.visualViewport;
        let frame = 0;
        let lastHeight = -1;
        let lastTop = -1;

        const apply = () => {
            frame = 0;
            const height = Math.round(viewport ? viewport.height : window.innerHeight);
            const top = Math.round(viewport ? viewport.offsetTop : 0);
            if (height === lastHeight && top === lastTop) {
                return;
            }
            const shrank = lastHeight > 0 && height < lastHeight;
            lastHeight = height;
            lastTop = top;
            target.style.setProperty('--live-chat-viewport-height', `${height}px`);
            target.style.setProperty('--live-chat-viewport-top', `${top}px`);
            target.toggleAttribute('data-live-chat-keyboard', window.innerHeight - height > KEYBOARD_THRESHOLD);
            const log = logRef.current;
            if (shrank && log) {
                log.scrollTop = log.scrollHeight;
            }
        };
        const schedule = () => {
            if (!frame) {
                frame = window.requestAnimationFrame(apply);
            }
        };

        apply();
        viewport?.addEventListener('resize', schedule);
        viewport?.addEventListener('scroll', schedule);
        return () => {
            window.cancelAnimationFrame(frame);
            viewport?.removeEventListener('resize', schedule);
            viewport?.removeEventListener('scroll', schedule);
            target.removeAttribute('data-live-chat-keyboard');
        };
    }, [messaging, opened, isMobile]);

    useEffect(() => {
        const log = logRef.current;
        if (log) {
            log.scrollTop = log.scrollHeight;
        }
    }, [messages, typing, restoring, askContact, opened, refundOpen]);

    const sendText = (text: string) => {
        const content = text.trim();
        if (!content || !sessionId || postMutation.isPending || revealing) {
            return false;
        }

        const optimistic: LiveChatMessage = {id: -Date.now(), author_type: 'VISITOR', content, created_at: new Date().toISOString()};
        setMessages((current) => [...current, optimistic]);

        const typingFrom = Date.now() + readingDelay();
        clearTimers();
        timers.current.push(window.setTimeout(() => setTyping(true), typingFrom - Date.now()));

        postMutation.mutate({sessionId, message: content}, {
            onSuccess: ({data}) => {
                if (data.contact_form_fallback) {
                    setTyping(false);
                    applyTurn(data);
                    setFallback({notice: null, message: content});
                    return;
                }
                void revealTurn(data, typingFrom);
            },
            onError: (error: any) => {
                clearTimers();
                setTyping(false);
                const status = statusOf(error);
                if (status === 429) {
                    setFallback({notice: error?.response?.data?.message ?? null, message: content});
                    return;
                }
                if (status === 404) {
                    storeSession(organizer.id, null);
                    setSessionId(null);
                    setFallback({notice: null, message: content});
                    return;
                }
                setMessages((current) => current.filter((message) => message.id !== optimistic.id));
                setDraft(content);
            },
        });
        return true;
    };

    const send = (formEvent?: FormEvent) => {
        formEvent?.preventDefault();
        if (sendText(draft)) {
            setDraft('');
        }
    };

    const onKeyDown = (keyEvent: KeyboardEvent<HTMLTextAreaElement>) => {
        if (keyEvent.key === 'Enter' && !keyEvent.shiftKey && !keyEvent.nativeEvent.isComposing) {
            keyEvent.preventDefault();
            send();
        }
    };

    const submitContact = (formEvent: FormEvent) => {
        formEvent.preventDefault();
        const value = contact.trim();

        if (!value) {
            setContactError(t(i18n)`Enter your phone number or your email.`);
            return;
        }
        const isEmail = value.includes('@');
        if (isEmail && !EMAIL_PATTERN.test(value)) {
            setContactError(t(i18n)`This email does not look valid.`);
            return;
        }
        if (!isEmail && !PHONE_PATTERN.test(value)) {
            setContactError(t(i18n)`This phone number does not look valid.`);
            return;
        }
        if (!sessionId) {
            return;
        }

        setContactError(null);
        contactMutation.mutate({
            sessionId,
            phone: isEmail ? null : value,
            email: isEmail ? value : null,
        }, {
            onSuccess: ({data}) => applyTurn(data),
            onError: (error: any) => {
                const errors = error?.response?.data?.errors;
                setContactError(errors?.phone?.[0] || errors?.email?.[0] || t(i18n)`We could not save your details. Please try again.`);
            },
        });
    };

    const dismissContact = () => {
        setContactDismissed(true);
        if (sessionId) {
            storeContactDismissed(sessionId);
        }
    };

    // Takes the visitor to the ticket form: the chat closes first so that the page can scroll again.
    const book = () => {
        onClose();
        window.setTimeout(() => {
            if (onBook) {
                onBook();
                return;
            }
            const tickets = document.getElementById(TICKETS_SECTION_ID);
            if (tickets) {
                scrollToTicketsSection(tickets);
            } else if (event) {
                window.location.assign(`${eventHomepagePath(event)}?${BUY_QUERY_PARAM}=1`);
            }
        }, CLOSE_BEFORE_BOOK_MS);
    };

    const organizerName = config.organizer_name || organizer.name || '';
    const welcome = greeting || config.greeting || t(i18n)`Hello! Ask me your question about our events, I will answer right away.`;
    const welcomeAt = messages.find((message) => message.id > 0)?.created_at ?? openedAt;
    const privacyLink = (
        <Anchor href={getPrivacyPolicyUrl()} target="_blank" rel="noopener" inherit>
            {t(i18n)`Privacy policy`}
        </Anchor>
    );

    const header = messaging ? (
        <div className={classes.messagingHeader}>
            <ActionIcon
                variant="subtle"
                color="white"
                radius="xl"
                size="lg"
                onClick={onClose}
                aria-label={t(i18n)`Close the chat`}
                className={classes.messagingBack}
            >
                <IconArrowLeft size={22}/>
            </ActionIcon>
            <span className={classes.messagingAvatarWrap}>
                <LiveChatAvatar url={config.avatar_url} size={40}/>
                <span className={classes.onlineDot} aria-hidden="true"/>
            </span>
            <span className={classes.messagingIdentity}>
                <span className={classes.messagingName}>{ASSISTANT_NAME}</span>
                <span className={classes.messagingStatus}>{t(i18n)`Online`}</span>
            </span>
        </div>
    ) : (
        <div className={classes.header}>
            <span className={classes.title}>{t(i18n)`Assistance`}</span>
            <span className={classes.organizerName}>{organizerName}</span>
            {config.relay_line && <span className={classes.relayLine}>{config.relay_line}</span>}
        </div>
    );

    const transparencyLine = (
        <Select
            value={startsWithVowel(organizerName)}
            _yes={`Virtual assistant of ${organizerName}`}
            other={`Virtual assistant of ${organizerName}`}
        />
    );

    let lastAgentIndex = -1;
    messages.forEach((message, index) => {
        if (message.author_type !== 'VISITOR') {
            lastAgentIndex = index;
        }
    });

    const tickState = (message: LiveChatMessage, index: number): TickState => {
        if (message.id < 0) {
            return 'sent';
        }
        return index < lastAgentIndex || typing ? 'read' : 'delivered';
    };

    const bubbleMeta = (createdAt: string | null, ticks?: TickState) => messaging ? (
        <span className={classes.bubbleMeta}>
            <span className={classes.bubbleTime}>{formatTime(createdAt)}</span>
            {ticks && <ReadTicks state={ticks}/>}
        </span>
    ) : null;

    const desktopPanel = messaging && !isMobile;
    const fullScreen = messaging && isMobile;
    const visitorHasWritten = messages.some((message) => message.author_type === 'VISITOR');
    const busy = !sessionId || postMutation.isPending || revealing;
    const showQuickReplies = messaging && !visitorHasWritten && !fallback && !restoring && !startMutation.isPending;
    const showContactForm = askContact && !contactGiven && !refundOpen && !contactDismissed
        && (!messaging || pendingQuestion);

    const eventBar = messaging && event ? (
        <LiveChatEventBar event={event} i18n={i18n} onBook={book}/>
    ) : null;

    const drawer = (portalTarget?: HTMLElement) => {
        portalRef.current = portalTarget;
        return (
            <Drawer
                opened={opened}
                onClose={onClose}
                portalProps={portalTarget ? {target: portalTarget} : undefined}
                position="right"
                size={isMobile ? '100%' : (messaging ? 440 : 420)}
                title={header}
                withCloseButton={!messaging}
                overlayProps={messaging ? {backgroundOpacity: isMobile ? 0 : 0.25, blur: 0} : undefined}
                transitionProps={fullScreen ? {
                    transition: {
                        in: {opacity: 1, transform: 'translate3d(0, 0, 0)'},
                        out: {opacity: 0, transform: 'translate3d(0, 24px, 0)'},
                        transitionProperty: 'opacity, transform',
                    },
                    duration: 200,
                    timingFunction: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
                } : undefined}
                styles={{
                    inner: desktopPanel ? {alignItems: 'flex-end', padding: 24} : undefined,
                    content: {
                        display: 'flex',
                        flexDirection: 'column',
                        height: '100%',
                        ...(desktopPanel ? {height: 'min(760px, calc(100dvh - 48px))', borderRadius: 16, overflow: 'hidden'} : {}),
                    },
                    header: messaging ? {padding: 0, alignItems: 'stretch'} : {padding: '16px 16px 12px', alignItems: 'flex-start'},
                    title: {flex: 1, minWidth: 0},
                    body: {display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0, padding: 0},
                }}
                classNames={{
                    content: `${classes.content} ${messaging ? classes.messaging : ''} ${fullScreen ? classes.messagingFullScreen : ''}`,
                    header: messaging ? classes.messagingDrawerHeader : classes.drawerHeader,
                    body: classes.body,
                    close: classes.close,
                }}
                closeButtonProps={{'aria-label': t(i18n)`Close the chat`}}
                aria-label={messaging ? t(i18n)`Chat with ${ASSISTANT_NAME}` : t(i18n)`Assistance`}
            >
                {fallback ? (
                    <div className={classes.fallback}>
                        <Text size="sm" mb="md">
                            {fallback.notice || t(i18n)`The assistant cannot answer right now. Leave your message and the organizer will reply by email.`}
                        </Text>
                        <ContactOrganizerForm organizer={organizer} onDone={onClose} initialMessage={fallback.message}/>
                    </div>
                ) : (
                    <>
                        {eventBar}
                        <div
                            ref={logRef}
                            className={classes.log}
                            role="log"
                            aria-live="polite"
                            aria-relevant="additions"
                            aria-label={t(i18n)`Conversation`}
                        >
                            {messaging && (
                                <div className={classes.systemNotice}>
                                    <span>{transparencyLine}</span>
                                    {config.relay_line && <span>{config.relay_line}</span>}
                                    <span className={classes.systemNoticeLegal}>
                                        {t(i18n)`Without contact details, messages are deleted after 90 days.`} {privacyLink}
                                    </span>
                                </div>
                            )}
                            <div className={`${classes.bubble} ${classes.agent}`}>
                                {messaging && <VisuallyHidden>{t(i18n)`Anicka:`}</VisuallyHidden>}
                                {welcome}
                                {bubbleMeta(welcomeAt)}
                            </div>
                            {messages.map((message, index) => (
                                <div
                                    key={message.id}
                                    className={`${classes.bubble} ${message.author_type === 'VISITOR' ? classes.visitor : classes.agent}`}
                                >
                                    <VisuallyHidden>{message.author_type === 'VISITOR' ? t(i18n)`You:` : t(i18n)`Anicka:`}</VisuallyHidden>
                                    {message.content}
                                    {bubbleMeta(message.created_at, message.author_type === 'VISITOR' ? tickState(message, index) : undefined)}
                                </div>
                            ))}
                            {(typing || startMutation.isPending || restoring) && (
                                <div className={`${classes.bubble} ${classes.agent} ${classes.typing}`} role="status" aria-label={t(i18n)`Anicka is writing`}>
                                    <span/><span/><span/>
                                </div>
                            )}
                            {startFailed && (
                                <div className={classes.error}>
                                    <Text size="sm">{t(i18n)`The chat could not start.`}</Text>
                                    <Button size="xs" variant="light" onClick={start}>{t(i18n)`Try again`}</Button>
                                </div>
                            )}
                            {refundOpen && sessionId && (
                                <LiveChatRefundCard
                                    sessionId={sessionId}
                                    onMessages={appendMessages}
                                    onUnavailable={(ask, given) => {
                                        setRefundOpen(false);
                                        setContactGiven(given);
                                        setAskContact(ask && !given);
                                    }}
                                    onDone={() => setRefundOpen(false)}
                                    onLimit={(notice) => {
                                        setRefundOpen(false);
                                        setFallback({notice, message: ''});
                                    }}
                                />
                            )}
                            {showContactForm && (
                                <form className={classes.contactForm} onSubmit={submitContact} noValidate>
                                    <div className={classes.contactRow}>
                                        <TextInput
                                            aria-label={t(i18n)`Your number or your email`}
                                            placeholder={t(i18n)`Your number or your email`}
                                            type="text"
                                            autoComplete="tel"
                                            value={contact}
                                            onChange={(changeEvent) => setContact(changeEvent.currentTarget.value)}
                                            maxLength={255}
                                            className={classes.contactInput}
                                            radius="xl"
                                            error={contactError ? true : undefined}
                                        />
                                        <Button type="submit" radius="xl" loading={contactMutation.isPending}>
                                            {t(i18n)`Send`}
                                        </Button>
                                    </div>
                                    {contactError && <Text size="xs" c="red" role="alert">{contactError}</Text>}
                                    <button type="button" className={classes.contactLater} onClick={dismissContact}>
                                        {t(i18n)`Later`}
                                    </button>
                                </form>
                            )}
                        </div>

                        {showQuickReplies && (
                            <div className={classes.quickReplies}>
                                <button
                                    type="button"
                                    className={classes.quickReply}
                                    disabled={busy}
                                    onClick={() => sendText(t(i18n)`The prices`)}
                                >
                                    {t(i18n)`The prices`}
                                </button>
                                <button
                                    type="button"
                                    className={classes.quickReply}
                                    disabled={busy}
                                    onClick={() => sendText(t(i18n)`The times`)}
                                >
                                    {t(i18n)`The times`}
                                </button>
                                {event && (
                                    <button type="button" className={`${classes.quickReply} ${classes.quickReplyPrimary}`} onClick={book}>
                                        {t(i18n)`I'm booking my place`}
                                    </button>
                                )}
                            </div>
                        )}

                        <form className={classes.composer} onSubmit={send}>
                            <Textarea
                                value={draft}
                                onChange={(changeEvent) => setDraft(changeEvent.currentTarget.value)}
                                onKeyDown={onKeyDown}
                                placeholder={messaging ? t(i18n)`Message` : t(i18n)`Write your question...`}
                                aria-label={t(i18n)`Your message`}
                                autosize
                                minRows={1}
                                maxRows={5}
                                maxLength={maxLength}
                                className={classes.input}
                                radius={messaging ? 'xl' : undefined}
                                data-autofocus={isMobile ? undefined : true}
                            />
                            <ActionIcon
                                type="submit"
                                size={messaging ? 44 : 'lg'}
                                radius="xl"
                                aria-label={t(i18n)`Send`}
                                className={messaging ? classes.sendButton : undefined}
                                disabled={busy || !draft.trim()}
                            >
                                <IconSend size={messaging ? 20 : 18}/>
                            </ActionIcon>
                        </form>
                        {!messaging && (
                            <p className={classes.notice}>
                                <Trans>
                                    Your messages are answered by the organizer's automatic assistant and passed on to the organizer
                                    if needed. Without contact details they are deleted after 90 days.
                                </Trans>{' '}
                                {privacyLink}
                            </p>
                        )}
                    </>
                )}
            </Drawer>
        );
    };

    const content = messaging ? <LiveChatMessagingScope>{drawer}</LiveChatMessagingScope> : drawer();

    return <I18nProvider i18n={i18n}>{content}</I18nProvider>;
};

export default LiveChatWindow;
