import {
    IconAddressBook,
    IconBrandStripe,
    IconCalendar,
    IconChartBar,
    IconCreditCard,
    IconExternalLink,
    IconHome,
    IconPaint,
    IconPlus,
    IconSettings,
    IconUsersGroup,
    IconQrcode,
    IconMessage,
    IconSpeakerphone
} from "@tabler/icons-react";
import { t } from "@lingui/macro";
import { NavItem } from "../AppLayout/types.ts";
import { ManageShell } from "../ManageShell";
import { Navigate, useLocation, useNavigate, useParams } from "react-router";
import { Button } from "@mantine/core";
import { useGetOrganizer } from "../../../queries/useGetOrganizer.ts";
import { useState } from "react";
import classes from "./OrganizerLayout.module.scss";
import { CalloutConfig, SidebarCalloutQueue } from "../../common/SidebarCallout/SidebarCalloutQueue";
import { InviteUserModal } from "../../modals/InviteUserModal";
import { useDisclosure } from "@mantine/hooks";
import { SwitchOrganizerModal } from "../../modals/SwitchOrganizerModal";
import { CreateOrganizerModal } from "../../modals/CreateOrganizerModal";
import { useGetOrganizers } from "../../../queries/useGetOrganizers.ts";
import { useGetAccount } from "../../../queries/useGetAccount.ts";
import { StripeConnectButton } from "../../common/StripeConnectButton";
import { useGetMe } from "../../../queries/useGetMe.ts";
import { isBoxOfficeOperator } from "../../../utilites/kioskAuth.ts";
import { useGetSmsUnreadCount } from "../../../queries/useGetSmsUnreadCount.ts";
import { useGetEmailUnreadCount } from "../../../queries/useGetEmailUnreadCount.ts";

const OrganizerLayout = () => {
    const { organizerId } = useParams();
    const navigate = useNavigate();
    const location = useLocation();
    const { data: organizer } = useGetOrganizer(organizerId);
    const [showCreateOrganizerModal, setShowCreateOrganizerModal] = useState(false);
    const [createModalOpen, { open: openCreateModal, close: closeCreateModal }] = useDisclosure(false);
    const [switchOrganizerModalOpen, { open: openSwitchModal, close: closeSwitchModal }] = useDisclosure(false);
    const { data: organizerResposne } = useGetOrganizers();
    const organizers = organizerResposne?.data;
    const { data: account } = useGetAccount();
    const { data: me, isSuccess: isMeSuccess } = useGetMe();
    const { data: smsUnreadCount } = useGetSmsUnreadCount(organizerId);
    const { data: emailUnreadCount } = useGetEmailUnreadCount(organizerId);
    const messagesUnreadCount = (smsUnreadCount ?? 0) + (emailUnreadCount ?? 0);

    if (isMeSuccess && isBoxOfficeOperator(me)) {
        return <Navigate to="/kiosk" replace />;
    }


    const isQrCodesPage = /\/(qr-codes|sms)\/?$/.test(location.pathname);
    const isDashboard = (isActive: boolean) => isActive || /\/manage\/organizer\/[^/]+\/?$/.test(location.pathname);

    const navItems: NavItem[] = [
        {link: 'dashboard', label: t`Home`, icon: IconHome, isActive: isDashboard},
        {link: 'events', label: t`Events`, icon: IconCalendar},
        {
            link: 'contacts',
            label: t`Contacts`,
            icon: IconAddressBook,
            isActive: (isActive) => isActive || location.pathname.includes('/contacts/'),
        },
        {
            link: 'reports',
            label: t`Reports`,
            icon: IconChartBar,
            isActive: (isActive) => isActive || location.pathname.includes('/report/')
        },
        {link: 'qr-codes', label: t`QR codes`, icon: IconQrcode},
        {link: 'sms', label: t`Messages`, icon: IconMessage, badge: messagesUnreadCount ? messagesUnreadCount : undefined},
        {link: 'publications', label: t`Publications`, icon: IconSpeakerphone},
    ];



    const bottomItems: NavItem[] = organizer ? [
        {link: `/events/${organizerId}/${organizer.slug || ''}`, label: t`My organizer page`, icon: IconExternalLink},
        {link: 'organizer-homepage-designer', label: t`Customize my page`, icon: IconPaint},
        {link: 'settings', label: t`Settings`, icon: IconSettings},
    ] : [];

    const callouts: CalloutConfig[] = [
        {
            icon: <IconUsersGroup size={20} />,
            heading: t`Invite Your Team`,
            description: t`Collaborate with your team to create amazing events together.`,
            buttonIcon: <IconUsersGroup size={16} />,
            buttonText: t`Invite Team Members`,
            onClick: () => {
                openCreateModal();
            },
            storageKey: `organizer-${organizerId}-team-callout-dismissed`
        },
    ];

    if (account && !account?.stripe_connect_setup_complete) {
        callouts.unshift({
            icon: <IconBrandStripe size={20} />,
            heading: t`Connect Stripe`,
            description: t`Connect your Stripe account to accept payments for tickets and products.`,
            storageKey: `stripe-callout-dismissed`,
            customButton:
                <StripeConnectButton
                    fullWidth
                    variant="white"
                    buttonIcon={<IconCreditCard size={16} />}
                    buttonText={t`Connect Stripe`}
                    className={classes.calloutButton}
                />
        });
    }

    return (
        <>
            <ManageShell
                organizerName={organizer?.name}
                onSwitchOrganizer={organizers && organizers.length > 1 ? openSwitchModal : undefined}
                navItems={navItems}
                bottomItems={bottomItems}
                mobileTabs={navItems.filter(item => item.link !== 'publications')}
                headerActions={isQrCodesPage ? undefined : (
                    <Button radius="md" size="md" leftSection={<IconPlus size={18}/>}
                            onClick={() => navigate(`/manage/new-event?organizer=${organizerId}`)}>
                        {t`Create Event`}
                    </Button>
                )}
                sidebarFooter={<SidebarCalloutQueue callouts={callouts} />}
            />

            {createModalOpen && <InviteUserModal onClose={closeCreateModal} />}
            {switchOrganizerModalOpen &&
                <SwitchOrganizerModal
                    opened={switchOrganizerModalOpen}
                    onClose={closeSwitchModal}
                    onCreateOrganizer={() => setShowCreateOrganizerModal(true)}
                />}
            {showCreateOrganizerModal && (
                <CreateOrganizerModal onClose={() => setShowCreateOrganizerModal(false)} />
            )}

        </>
    );
};

export default OrganizerLayout;
