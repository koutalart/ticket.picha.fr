import {FormEvent, KeyboardEvent, useEffect, useRef, useState} from "react";
import {ActionIcon, Anchor, Button, Drawer, Group, Text, Textarea, TextInput, VisuallyHidden} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import {select, t, Trans} from "@lingui/macro";
import {IconArrowLeft, IconSend} from "@tabler/icons-react";
import {IdParam, LiveChatConfig, LiveChatMessage, LiveChatTurn, Organizer} from "../../../types.ts";
import {usePostLiveChatMessage, useStartLiveChat, useSubmitLiveChatContact} from "../../../mutations/useLiveChat.ts";
import {liveChatPublicClient} from "../../../api/live-chat.client.ts";
import {getPrivacyPolicyUrl} from "../../../utilites/branding.ts";
import {ContactOrganizerForm} from "../ContactOrganizerModal/ContactOrganizerForm.tsx";
import {LiveChatRefundCard} from "./LiveChatRefundCard.tsx";
import {ASSISTANT_NAME, LiveChatAvatar} from "./LiveChatAvatar.tsx";
import {LiveChatMessagingScope} from "./LiveChatMessagingScope.tsx";
import classes from "./LiveChatWindow.module.scss";

interface LiveChatWindowProps {
    opened: boolean;
    onClose: () => void;
    organizer: Organizer;
    eventId?: IdParam;
    config: LiveChatConfig;
}

const STORAGE_PREFIX = 'picha-live-chat:';
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

const statusOf = (error: any): number | undefined => error?.response?.status;

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
export const LiveChatWindow = ({opened, onClose, organizer, eventId, config}: LiveChatWindowProps) => {
    const isMobile = useMediaQuery('(max-width: 48em)');
    const messaging = config.style === 'MESSAGING';
    const [greetingAt, setGreetingAt] = useState<string | null>(null);
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [greeting, setGreeting] = useState<string | null>(null);
    const [messages, setMessages] = useState<LiveChatMessage[]>([]);
    const [draft, setDraft] = useState('');
    const [askContact, setAskContact] = useState(false);
    const [contactGiven, setContactGiven] = useState(false);
    const [fallback, setFallback] = useState<{ notice: string | null; message: string } | null>(null);
    const [phone, setPhone] = useState('');
    const [email, setEmail] = useState('');
    const [contactError, setContactError] = useState<string | null>(null);
    const [startFailed, setStartFailed] = useState(false);
    const [refundOpen, setRefundOpen] = useState(false);
    const [typing, setTyping] = useState(false);
    const [revealing, setRevealing] = useState(false);
    const [pendingQuestion, setPendingQuestion] = useState(false);
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
        setMessages((current) => replaceAll ? turn.messages : [...current.filter((message) => message.id > 0), ...turn.messages]);
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
                setGreeting(data.greeting);
                setGreetingAt(new Date().toISOString());
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

        liveChatPublicClient.messages(stored)
            .then(({data}) => {
                setSessionId(stored);
                setGreeting(null);
                applyTurn(data, true);
            })
            .catch(() => {
                storeSession(organizer.id, null);
                start();
            });
    }, [opened]);

    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

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
    // input bar stays visible above the keyboard.
    useEffect(() => {
        if (!messaging || !opened || !isMobile || typeof window === 'undefined' || !window.visualViewport) {
            return;
        }
        const viewport = window.visualViewport;
        let frame = 0;
        const update = () => {
            window.cancelAnimationFrame(frame);
            frame = window.requestAnimationFrame(() => {
                const content = logRef.current?.closest<HTMLElement>('.mantine-Drawer-content');
                if (!content) {
                    return;
                }
                content.style.setProperty('--live-chat-viewport-height', `${Math.round(viewport.height)}px`);
                content.style.setProperty('--live-chat-viewport-top', `${Math.round(viewport.offsetTop)}px`);
                const log = logRef.current;
                if (log) {
                    log.scrollTop = log.scrollHeight;
                }
            });
        };
        update();
        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);
        return () => {
            window.cancelAnimationFrame(frame);
            viewport.removeEventListener('resize', update);
            viewport.removeEventListener('scroll', update);
        };
    }, [messaging, opened, isMobile]);

    useEffect(() => {
        const log = logRef.current;
        if (log) {
            log.scrollTop = log.scrollHeight;
        }
    }, [messages, typing, askContact, opened, refundOpen]);

    const send = (event?: FormEvent) => {
        event?.preventDefault();
        const content = draft.trim();
        if (!content || !sessionId || postMutation.isPending || revealing) {
            return;
        }

        const optimistic: LiveChatMessage = {id: -Date.now(), author_type: 'VISITOR', content, created_at: new Date().toISOString()};
        setMessages((current) => [...current, optimistic]);
        setDraft('');

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
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            send();
        }
    };

    const submitContact = (event: FormEvent) => {
        event.preventDefault();
        const trimmedPhone = phone.trim();
        const trimmedEmail = email.trim();

        if (!trimmedPhone && !trimmedEmail) {
            setContactError(t`Enter your phone number or your email.`);
            return;
        }
        if (trimmedPhone && !PHONE_PATTERN.test(trimmedPhone)) {
            setContactError(t`This phone number does not look valid.`);
            return;
        }
        if (!trimmedPhone && !EMAIL_PATTERN.test(trimmedEmail)) {
            setContactError(t`This email does not look valid.`);
            return;
        }
        if (!sessionId) {
            return;
        }

        setContactError(null);
        contactMutation.mutate({
            sessionId,
            phone: trimmedPhone || null,
            email: trimmedPhone ? null : trimmedEmail,
        }, {
            onSuccess: ({data}) => applyTurn(data),
            onError: (error: any) => {
                const errors = error?.response?.data?.errors;
                setContactError(errors?.phone?.[0] || errors?.email?.[0] || t`We could not save your details. Please try again.`);
            },
        });
    };

    const organizerName = config.organizer_name || organizer.name || '';

    const header = messaging ? (
        <div className={classes.messagingHeader}>
            <ActionIcon
                variant="subtle"
                color="white"
                radius="xl"
                size="lg"
                onClick={onClose}
                aria-label={t`Close the chat`}
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
                <span className={classes.messagingStatus}>{t`Online`}</span>
            </span>
        </div>
    ) : (
        <div className={classes.header}>
            <span className={classes.title}>{t`Assistance`}</span>
            <span className={classes.organizerName}>{organizerName}</span>
            {config.relay_line && <span className={classes.relayLine}>{config.relay_line}</span>}
        </div>
    );

    const transparencyLine = select(startsWithVowel(organizerName), {
        yes: `Virtual assistant of ${organizerName}`,
        other: `Virtual assistant of ${organizerName}`,
    });

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

    const drawer = (portalTarget?: HTMLElement) => (
        <Drawer
            opened={opened}
            onClose={onClose}
            portalProps={portalTarget ? {target: portalTarget} : undefined}
            position="right"
            size={isMobile ? '100%' : (messaging ? 440 : 420)}
            title={header}
            withCloseButton={!messaging}
            overlayProps={messaging ? {backgroundOpacity: isMobile ? 0 : 0.25, blur: 0} : undefined}
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
                content: `${classes.content} ${messaging ? classes.messaging : ''} ${messaging && isMobile ? classes.messagingFullScreen : ''}`,
                header: messaging ? classes.messagingDrawerHeader : classes.drawerHeader,
                body: classes.body,
                close: classes.close,
            }}
            closeButtonProps={{'aria-label': t`Close the chat`}}
            aria-label={messaging ? t`Chat with ${ASSISTANT_NAME}` : t`Assistance`}
        >
            {fallback ? (
                <div className={classes.fallback}>
                    <Text size="sm" mb="md">
                        {fallback.notice || t`The assistant cannot answer right now. Leave your message and the organizer will reply by email.`}
                    </Text>
                    <ContactOrganizerForm organizer={organizer} onDone={onClose} initialMessage={fallback.message}/>
                </div>
            ) : (
                <>
                    <div
                        ref={logRef}
                        className={classes.log}
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                        aria-label={t`Conversation`}
                    >
                        {messaging && (
                            <div className={classes.systemNotice}>
                                <span>{transparencyLine}</span>
                                {config.relay_line && <span>{config.relay_line}</span>}
                            </div>
                        )}
                        {greeting && (
                            <div className={`${classes.bubble} ${classes.agent}`}>
                                {messaging && <VisuallyHidden>{t`Anicka:`}</VisuallyHidden>}
                                {greeting}
                                {bubbleMeta(greetingAt)}
                            </div>
                        )}
                        {messages.map((message, index) => (
                            <div
                                key={message.id}
                                className={`${classes.bubble} ${message.author_type === 'VISITOR' ? classes.visitor : classes.agent}`}
                            >
                                <VisuallyHidden>{message.author_type === 'VISITOR' ? t`You:` : t`Anicka:`}</VisuallyHidden>
                                {message.content}
                                {bubbleMeta(message.created_at, message.author_type === 'VISITOR' ? tickState(message, index) : undefined)}
                            </div>
                        ))}
                        {(typing || startMutation.isPending) && (
                            <div className={`${classes.bubble} ${classes.agent} ${classes.typing}`} role="status" aria-label={t`Anicka is writing`}>
                                <span/><span/><span/>
                            </div>
                        )}
                        {startFailed && (
                            <div className={classes.error}>
                                <Text size="sm">{t`The chat could not start.`}</Text>
                                <Button size="xs" variant="light" onClick={start}>{t`Try again`}</Button>
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
                        {askContact && !contactGiven && !refundOpen && (
                            <form className={classes.contactForm} onSubmit={submitContact} noValidate>
                                <Text size="sm" fw={600}>{t`So that the organizer can reply to you`}</Text>
                                <TextInput
                                    label={t`Phone (reply by SMS)`}
                                    placeholder="0639 12 34 56"
                                    type="tel"
                                    autoComplete="tel"
                                    inputMode="tel"
                                    value={phone}
                                    onChange={(event) => setPhone(event.currentTarget.value)}
                                    maxLength={30}
                                />
                                <TextInput
                                    label={t`Or your email`}
                                    placeholder={t`you@example.com`}
                                    type="email"
                                    autoComplete="email"
                                    value={email}
                                    onChange={(event) => setEmail(event.currentTarget.value)}
                                    maxLength={255}
                                    disabled={phone.trim() !== ''}
                                />
                                {contactError && <Text size="xs" c="red" role="alert">{contactError}</Text>}
                                <Group justify="flex-end">
                                    <Button type="submit" size="xs" loading={contactMutation.isPending}>{t`Send my details`}</Button>
                                </Group>
                            </form>
                        )}
                    </div>

                    <form className={classes.composer} onSubmit={send}>
                        <Textarea
                            value={draft}
                            onChange={(event) => setDraft(event.currentTarget.value)}
                            onKeyDown={onKeyDown}
                            placeholder={messaging ? t`Message` : t`Write your question...`}
                            aria-label={t`Your message`}
                            autosize
                            minRows={1}
                            maxRows={5}
                            maxLength={maxLength}
                            className={classes.input}
                            radius={messaging ? 'xl' : undefined}
                            disabled={!sessionId}
                            data-autofocus
                        />
                        <ActionIcon
                            type="submit"
                            size={messaging ? 44 : 'lg'}
                            radius="xl"
                            aria-label={t`Send`}
                            className={messaging ? classes.sendButton : undefined}
                            disabled={!sessionId || !draft.trim() || postMutation.isPending || revealing}
                        >
                            <IconSend size={messaging ? 20 : 18}/>
                        </ActionIcon>
                    </form>
                    <p className={classes.notice}>
                        <Trans>
                            Your messages are answered by the organizer's automatic assistant and passed on to the organizer
                            if needed. Without contact details they are deleted after 90 days.
                        </Trans>{' '}
                        <Anchor href={getPrivacyPolicyUrl()} target="_blank" rel="noopener" inherit>
                            {t`Privacy policy`}
                        </Anchor>
                    </p>
                </>
            )}
        </Drawer>
    );

    return messaging ? <LiveChatMessagingScope>{drawer}</LiveChatMessagingScope> : drawer();
};

export default LiveChatWindow;
