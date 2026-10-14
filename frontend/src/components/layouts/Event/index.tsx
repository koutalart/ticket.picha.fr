import {
    IconCashRegister,
    IconChartBar,
    IconHome,
    IconLayoutDashboard,
    IconDeviceTabletCode,
    IconDiscount2,
    IconEye,
    IconEyeOff,
    IconBrowser,
    IconMailCheck,
    IconMailForward,
    IconPaint,
    IconQrcode,
    IconReceipt,
    IconSend,
    IconSettings,
    IconShare,
    IconTicket,
    IconTrendingUp,
    IconUserQuestion,
    IconUsers,
    IconUsersGroup,
    IconWebhook,
    IconListCheck,
} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent";
import {useGetEventSettings} from "../../../queries/useGetEventSettings";
import {useGetEventStats} from "../../../queries/useGetEventStats";
import {isFreeEvent} from "../../../utilites/eventPricing.ts";
import {NavItem} from "../AppLayout/types.ts";
import {ManageShell} from "../ManageShell";
import {NavLink, Navigate, useLocation, useParams} from "react-router";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {useUpdateEventStatus} from "../../../mutations/useUpdateEventStatus.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {ShareModal} from "../../modals/ShareModal";
import {EventLiveCelebrationModal} from "../../modals/EventLiveCelebrationModal";
import {useDisclosure} from "@mantine/hooks";
import {SidebarCallout} from "../../common/SidebarCallout";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useResendEmailConfirmation} from "../../../mutations/useResendEmailConfirmation.ts";
import {useState} from "react";
import {eventHomepageUrl} from "../../../utilites/urlHelper.ts";
import {isBoxOfficeOperator} from "../../../utilites/kioskAuth.ts";
import {useIsCurrentUserAdmin} from "../../../hooks/useIsCurrentUserAdmin.ts";

const EventLayout = () => {
    const location = useLocation();
    const {eventId} = useParams();

    const [opened, {open, close}] = useDisclosure(false);
    const [celebrationOpened, {open: openCelebration, close: closeCelebration}] = useDisclosure(false);

    const statusToggleMutation = useUpdateEventStatus();

    const {data: event, isFetched: isEventFetched} = useGetEvent(eventId);
    const {data: eventSettings, isFetched: isEventSettingsFetched} = useGetEventSettings(eventId);
    const {data: eventStats} = useGetEventStats(eventId);
    const ordersLabel = isFreeEvent(event) ? t({message: 'Registrations', context: 'orders page title'}) : t`Orders`;
    const {data: me, isSuccess: isMeSuccess} = useGetMe();
    const isAdmin = useIsCurrentUserAdmin();

    if (isMeSuccess && isBoxOfficeOperator(me)) {
        return <Navigate to="/kiosk" replace/>;
    }

    const resendEmailConfirmationMutation = useResendEmailConfirmation();
    const [emailConfirmationResent, setEmailConfirmationResent] = useState(false);

    const handleEmailConfirmationResend = () => {
        resendEmailConfirmationMutation.mutate({
            userId: me?.id
        }, {
            onSuccess: () => {
                showSuccess(t`Email confirmation resent successfully`);
                setEmailConfirmationResent(true);
            },
            onError: () => {
                showError(t`Something went wrong. Please try again.`);
            }
        })
    }

    const isLive = event?.status === 'LIVE';
    const showGettingStarted = () => !eventSettings?.hide_getting_started_page;

    const navItems: NavItem[] = [
        {link: 'getting-started', label: t`Getting Started`, icon: IconHome, showWhen: showGettingStarted},
        {link: 'dashboard', label: t`Dashboard`, icon: IconLayoutDashboard},
        {
            link: 'reports',
            label: t`Reports`,
            icon: IconChartBar,
            isActive: (isActive) => isActive || location.pathname.includes('/report/')
        },

        {label: t({message: 'Setup', context: 'event menu'})},
        {link: 'settings', label: t({message: 'Settings', context: 'event menu'}), icon: IconSettings},
        {link: 'homepage-designer', label: t({message: 'Event page', context: 'event menu'}), icon: IconBrowser},
        {link: 'products', label: t({message: 'Tickets', context: 'event menu'}), icon: IconTicket},
        {link: 'questions', label: t`Registration Questions`, icon: IconUserQuestion},

        {label: t({message: 'Sales', context: 'event menu'})},
        {link: 'orders', label: ordersLabel, icon: IconReceipt, badge: eventStats?.total_orders || undefined},
        {link: 'promo-codes', label: t({message: 'Promo codes', context: 'event menu'}), icon: IconDiscount2},
        {
            link: 'box-office',
            label: t`Box Office`,
            icon: IconCashRegister,
            isActive: (isActive) => isActive && !location.pathname.includes('/box-office/operators'),
        },

        {label: ''},
        {link: 'attendees', label: t`Attendees`, icon: IconUsers, badge: eventStats?.total_attendees_registered || undefined},
    ];

    const moreItems: NavItem[] = [
        {link: 'ticket-designer', label: t({message: 'Ticket design', context: 'event menu'}), icon: IconPaint},
        {link: 'check-in', label: t`Check-In Lists`, icon: IconQrcode},
        {link: 'messages', label: t({message: 'Messages', context: 'event menu'}), icon: IconSend},
        {link: 'sold-out-waitlist', label: t`Waitlist`, icon: IconListCheck},
        {link: 'capacity-assignments', label: t`Capacity Management`, icon: IconUsersGroup},
        {link: 'affiliates', label: t`Affiliates`, icon: IconTrendingUp},
        {link: 'box-office/operators', label: t`Kiosk operators`, icon: IconUsers, showWhen: () => isAdmin},
        {link: 'widget', label: t`Widget Embed`, icon: IconDeviceTabletCode},
        {link: 'webhooks', label: t`Webhooks`, icon: IconWebhook},
    ];

    const mobileTabs: NavItem[] = isLive || !showGettingStarted() ? [
        {link: 'dashboard', label: t`Dashboard`, icon: IconLayoutDashboard},
        {link: 'orders', label: ordersLabel, icon: IconReceipt},
        {link: 'box-office', label: t`Box Office`, icon: IconCashRegister},
    ] : [
        {link: 'getting-started', label: t`Getting Started`, icon: IconHome},
        {link: 'products', label: t({message: 'Tickets', context: 'event menu'}), icon: IconTicket},
        {link: 'orders', label: ordersLabel, icon: IconReceipt},
    ];

    const handleStatusToggle = () => {
        const isGoingLive = event?.status !== 'LIVE';
        const message = event?.status === 'LIVE'
            ? t`Are you sure you want to make this event draft? This will make the event invisible to the public`
            : t`Are you sure you want to make this event public? This will make the event visible to the public`;

        confirmationDialog(message, () => {
            statusToggleMutation.mutate({
                eventId,
                status: event?.status === 'LIVE' ? 'DRAFT' : 'LIVE'
            }, {
                onSuccess: () => {
                    if (isGoingLive) {
                        openCelebration();
                    } else {
                        showSuccess(t`Event status updated`);
                    }
                },
                onError: (error: any) => {
                    showError(error?.response?.data?.message || t`Event status update failed. Please try again later`);
                }
            });
        });
    };

    const bottomItems: NavItem[] = event ? [
        {link: `/event/${eventId}/${event.slug}`, label: t({message: 'Preview', context: 'event menu'}), icon: IconEye},
        {label: t({message: 'Share', context: 'event menu'}), icon: IconShare, onClick: open},
        {
            label: isLive ? t`Switch back to draft` : t`Publish the event`,
            icon: isLive ? IconEyeOff : IconEye,
            onClick: handleStatusToggle,
        },
    ] : [];

    return (
        <>
            <ManageShell
                backLink={event?.organizer?.id ? {to: `/manage/organizer/${event.organizer.id}`, label: t`My events`} : undefined}
                breadcrumbs={event && (
                    <>
                        <NavLink to={`/manage/organizer/${event.organizer?.id}`}>{t`My events`}</NavLink>
                        <span>/</span>
                        <strong>{event.title}</strong>
                    </>
                )}
                navItems={isEventFetched && isEventSettingsFetched ? navItems : []}
                moreItems={moreItems}
                bottomItems={bottomItems}
                mobileTabs={isEventFetched && isEventSettingsFetched ? mobileTabs : []}
                sidebarFooter={
                    (me && !me.is_email_verified && !emailConfirmationResent ? (
                        <SidebarCallout
                            icon={<IconMailCheck size={20}/>}
                            heading={t`Verify your email`}
                            description={t`Confirm your email to access all features.`}
                            buttonIcon={<IconMailForward size={16}/>}
                            buttonText={t`Resend email`}
                            onClick={() => handleEmailConfirmationResend()}
                        />
                    ) : null)
                }
            />

            {event && (
                <>
                    <ShareModal
                        url={eventHomepageUrl(event)}
                        title={event.title}
                        modalTitle={t`Share Event`}
                        opened={opened}
                        onClose={close}
                    />
                    <EventLiveCelebrationModal
                        opened={celebrationOpened}
                        onClose={closeCelebration}
                        url={eventHomepageUrl(event)}
                        eventTitle={event.title}
                        eventId={String(event.id)}
                    />
                </>
            )}
        </>
    );
};

export default EventLayout;
