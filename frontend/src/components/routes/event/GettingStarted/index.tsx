import {ReactNode, useEffect, useState} from 'react';
import {NavLink, useLocation, useNavigate, useParams} from "react-router";
import {Button, Progress} from "@mantine/core";
import {useDisclosure} from "@mantine/hooks";
import {IconAlertCircleFilled, IconBrowser, IconCircleCheckFilled, IconCreditCard, IconEye, IconFileDescription, IconShare, IconTicket} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useGetEvent} from "../../../../queries/useGetEvent.ts";
import {useGetEventImages} from "../../../../queries/useGetEventImages.ts";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {useGetAccount} from "../../../../queries/useGetAccount.ts";
import {useUpdateEventStatus} from "../../../../mutations/useUpdateEventStatus.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {getProductsFromEvent} from "../../../../utilites/helpers.ts";
import {eventHomepageUrl} from "../../../../utilites/urlHelper.ts";
import {AnalyticsEvents, trackEvent} from "../../../../utilites/analytics.ts";
import {Browser, useBrowser} from "../../../../hooks/useGetBrowser.ts";
import {ShareModal} from "../../../modals/ShareModal";
import {StripeConnectButton} from "../../../common/StripeConnectButton";
import {Product, ProductPriceType} from "../../../../types.ts";
import ConfettiAnimation from "./ConfettiAnimaiton";
import classes from "./GettingStarted.module.scss";

type StepState = 'done' | 'todo' | 'optional';

interface Step {
    key: string;
    icon: ReactNode;
    title: string;
    description: string;
    state: StepState;
    blocking: boolean;
    action: ReactNode;
}

const isPaid = (product: Product) => product.type !== ProductPriceType.Free
    && (product.prices ?? []).some((price) => Number(price.price) > 0);

const GettingStarted = () => {
    const {eventId} = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const browser = useBrowser();
    const [showConfetti, setShowConfetti] = useState(false);
    const [shareOpened, share] = useDisclosure(false);

    useEffect(() => {
        const searchParams = new URLSearchParams(location.search);
        if (searchParams.get('new_event') !== 'true') return;
        if (browser === Browser.Chrome || browser === Browser.Firefox) setShowConfetti(true);

        const timer = setTimeout(() => {
            searchParams.delete('new_event');
            navigate({pathname: location.pathname, search: searchParams.toString()}, {replace: true});
        }, 2000);
        return () => clearTimeout(timer);
    }, [location, navigate]);

    const {data: event} = useGetEvent(eventId);
    const {data: settings} = useGetEventSettings(eventId);
    const {data: images} = useGetEventImages(eventId);
    const {data: account} = useGetAccount();
    const statusMutation = useUpdateEventStatus();

    const products = getProductsFromEvent(event) ?? [];
    const hasProducts = products.length > 0;
    const hasDetails = Boolean(event?.description && event?.start_date);
    const hasPage = Boolean(images && images.length > 0);
    const stripeReady = Boolean(account?.stripe_connect_setup_complete);
    const offlineAllowed = settings?.payment_providers?.includes('OFFLINE') ?? false;
    const needsStripe = products.some(isPaid) && !offlineAllowed;
    const isLive = event?.status === 'LIVE';
    const base = `/manage/event/${eventId}`;

    const editLink = (to: string, done: boolean, todoLabel: string) => done
        ? <NavLink to={to} className={classes.editLink}><Trans>Edit</Trans></NavLink>
        : <Button component={NavLink} to={to} radius="md" variant="light"><>{todoLabel}</></Button>;

    const steps: Step[] = [
        {
            key: 'tickets',
            icon: <IconTicket size={22} stroke={1.5}/>,
            title: t`Tickets`,
            description: hasProducts ? t`Your tickets are ready.` : t`Create at least one ticket to start selling.`,
            state: hasProducts ? 'done' : 'todo',
            blocking: true,
            action: editLink(`${base}/products`, hasProducts, t`Add tickets`),
        },
        {
            key: 'details',
            icon: <IconFileDescription size={22} stroke={1.5}/>,
            title: t`Event details`,
            description: hasDetails ? t`Date, venue and details filled in.` : t`Add a description so people know what to expect.`,
            state: hasDetails ? 'done' : 'todo',
            blocking: true,
            action: editLink(`${base}/settings`, hasDetails, t`Complete`),
        },
        {
            key: 'page',
            icon: <IconBrowser size={22} stroke={1.5}/>,
            title: t({message: 'Event page', context: 'event menu'}),
            description: hasPage ? t`Your presentation is ready.` : t`Add your poster to make the page more attractive.`,
            state: hasPage ? 'done' : 'optional',
            blocking: false,
            action: editLink(`${base}/homepage-designer`, hasPage, t`Customize`),
        },
        {
            key: 'payments',
            icon: <IconCreditCard size={22} stroke={1.5}/>,
            title: t`Payments`,
            description: stripeReady
                ? t`Stripe is connected, you receive online payments.`
                : needsStripe
                    ? t`Connect Stripe to receive payments.`
                    : t`Not needed for now: your tickets are free or paid offline.`,
            state: stripeReady ? 'done' : needsStripe ? 'todo' : 'optional',
            blocking: needsStripe,
            action: stripeReady ? null : (
                <StripeConnectButton
                    buttonText={t`Connect Stripe`}
                    variant={needsStripe ? 'filled' : 'light'}
                />
            ),
        },
    ];

    const counted = steps.filter((step) => step.state !== 'optional' || step.key === 'page');
    const doneCount = counted.filter((step) => step.state === 'done').length;
    const missing = steps.filter((step) => step.blocking && step.state !== 'done');
    const canPublish = missing.length === 0;

    const stateLabel = (state: StepState) => {
        if (state === 'done') return <span className={classes.stateDone}><IconCircleCheckFilled size={20}/> <Trans>Done</Trans></span>;
        if (state === 'todo') return <span className={classes.stateTodo}><IconAlertCircleFilled size={20}/> <Trans>To complete</Trans></span>;
        return <span className={classes.stateOptional}><Trans>Optional</Trans></span>;
    };

    const setStatus = (status: 'LIVE' | 'DRAFT') => {
        const message = status === 'LIVE'
            ? t`Are you sure you want to make this event public? This will make the event visible to the public`
            : t`Are you sure you want to make this event draft? This will make the event invisible to the public`;

        confirmationDialog(message, () => {
            statusMutation.mutate({eventId, status}, {
                onSuccess: () => {
                    if (status === 'LIVE') trackEvent(AnalyticsEvents.EVENT_PUBLISHED);
                    showSuccess(status === 'LIVE' ? t`Your event is online!` : t`Event status updated`);
                },
                onError: (error: any) => showError(error?.response?.data?.message || t`Event status update failed. Please try again later`),
            });
        });
    };

    return (
        <>
            {showConfetti && <ConfettiAnimation duration={2000}/>}
            <div className={classes.page}>
                <header className={classes.header}>
                    <div className={classes.headerText}>
                        <div className={classes.titleRow}>
                            <h1 className={classes.title}>{event?.title}</h1>
                            {event && (
                                <span className={isLive ? classes.badgeLive : classes.badgeDraft}>
                                    {isLive ? t`Online` : t`Draft`}
                                </span>
                            )}
                        </div>
                        <p className={classes.subtitle}>
                            {isLive
                                ? <Trans>Your event is online. You can keep improving it at any time.</Trans>
                                : <Trans>Prepare your event before publishing it.</Trans>}
                        </p>
                    </div>
                    {event && (
                        <div className={classes.headerActions}>
                            <Button component="a" href={`/event/${eventId}/${event.slug}`} target="_blank" variant="subtle"
                                    radius="md" leftSection={<IconEye size={18}/>}>
                                {t({message: 'Preview', context: 'event menu'})}
                            </Button>
                            <Button variant="default" radius="md" leftSection={<IconShare size={18}/>} onClick={share.open}>
                                {t({message: 'Share', context: 'event menu'})}
                            </Button>
                        </div>
                    )}
                </header>

                <section className={classes.card}>
                    <div className={classes.cardHead}>
                        <h2 className={classes.cardTitle}><Trans>Event preparation</Trans></h2>
                        <p className={classes.cardSubtitle}>
                            <Trans>{doneCount} of {counted.length} steps done</Trans>
                        </p>
                        <Progress value={counted.length ? doneCount / counted.length * 100 : 0} radius="xl" size="sm"
                                  className={classes.progress}/>
                    </div>

                    {steps.map((step) => (
                        <div key={step.key} className={classes.step}>
                            <span className={classes.stepIcon}>{step.icon}</span>
                            <div className={classes.stepText}>
                                <span className={classes.stepTitle}>{step.title}</span>
                                <span className={classes.stepDescription}>{step.description}</span>
                            </div>
                            <div className={classes.stepState}>{stateLabel(step.state)}</div>
                            <div className={classes.stepAction}>{step.action}</div>
                        </div>
                    ))}
                </section>

                <section className={classes.publish}>
                    <h2 className={classes.sectionTitle}><Trans>Publication</Trans></h2>
                    {isLive ? (
                        <>
                            <p className={classes.muted}>
                                <Trans>Your event is visible to the public and tickets are on sale.</Trans>
                            </p>
                            <div className={classes.publishActions}>
                                <Button radius="md" size="md" leftSection={<IconShare size={18}/>} onClick={share.open}>
                                    <Trans>Share the event</Trans>
                                </Button>
                                <Button radius="md" size="md" variant="subtle" color="gray"
                                        loading={statusMutation.isPending} onClick={() => setStatus('DRAFT')}>
                                    <Trans>Switch back to draft</Trans>
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <p className={classes.muted}>
                                {canPublish
                                    ? <Trans>Everything essential is ready. You can put your event online.</Trans>
                                    : <Trans>Finish these steps to put your event online: {missing.map((step) => step.title.toLowerCase()).join(', ')}.</Trans>}
                            </p>
                            <Button radius="md" size="lg" disabled={!canPublish} loading={statusMutation.isPending}
                                    className={classes.publishButton} onClick={() => setStatus('LIVE')}>
                                <Trans>Publish the event</Trans>
                            </Button>
                            <p className={classes.hint}><Trans>Your event stays a draft until you publish it.</Trans></p>
                        </>
                    )}
                </section>
            </div>

            {event && (
                <ShareModal
                    url={eventHomepageUrl(event)}
                    title={event.title}
                    modalTitle={t`Share Event`}
                    opened={shareOpened}
                    onClose={share.close}
                />
            )}
        </>
    );
};

export default GettingStarted;
