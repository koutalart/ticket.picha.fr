import {
    IconBrandStripe,
    IconCalendar,
    IconChartBar,
    IconCreditCard,
    IconExternalLink,
    IconHome,
    IconEye,
    IconEyeOff,
    IconPaint,
    IconPlus,
    IconSettings,
    IconShare,
    IconUsersGroup,
    IconWebhook
} from "@tabler/icons-react";
import { t } from "@lingui/macro";
import { NavItem } from "../AppLayout/types.ts";
import { ManageShell } from "../ManageShell";
import { Navigate, useLocation, useNavigate, useParams } from "react-router";
import { Button, Modal, Stack, Text } from "@mantine/core";
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
import { ShareModal } from "../../modals/ShareModal";
import { organizerHomepageUrl } from "../../../utilites/urlHelper";
import { useUpdateOrganizerStatus } from "../../../mutations/useUpdateOrganizerStatus.ts";
import { confirmationDialog } from "../../../utilites/confirmationDialog.tsx";
import { showError, showSuccess } from "../../../utilites/notifications.tsx";
import { useResendEmailConfirmation } from "../../../mutations/useResendEmailConfirmation.ts";
import { useGetMe } from "../../../queries/useGetMe.ts";
import { isBoxOfficeOperator } from "../../../utilites/kioskAuth.ts";

const OrganizerLayout = () => {
    const { organizerId } = useParams();
    const navigate = useNavigate();
    const location = useLocation();
    const { data: organizer } = useGetOrganizer(organizerId);
    const [showCreateOrganizerModal, setShowCreateOrganizerModal] = useState(false);
    const [createModalOpen, { open: openCreateModal, close: closeCreateModal }] = useDisclosure(false);
    const [switchOrganizerModalOpen, { open: openSwitchModal, close: closeSwitchModal }] = useDisclosure(false);
    const [shareModalOpen, { open: openShareModal, close: closeShareModal }] = useDisclosure(false);
    const [emailVerificationModalOpen, {
        open: openEmailVerificationModal,
        close: closeEmailVerificationModal
    }] = useDisclosure(false);
    const { data: organizerResposne } = useGetOrganizers();
    const organizers = organizerResposne?.data;
    const { data: account } = useGetAccount();
    const resendEmailConfirmationMutation = useResendEmailConfirmation();
    const [emailConfirmationResent, setEmailConfirmationResent] = useState(false);
    const { data: me, isSuccess: isMeSuccess } = useGetMe();
    const isUserEmailVerfied = me?.is_email_verified;

    if (isMeSuccess && isBoxOfficeOperator(me)) {
        return <Navigate to="/kiosk" replace />;
    }

    const statusToggleMutation = useUpdateOrganizerStatus();

    const isDashboard = (isActive: boolean) => isActive || /\/manage\/organizer\/[^/]+\/?$/.test(location.pathname);

    const navItems: NavItem[] = [
        {link: 'dashboard', label: t`Home`, icon: IconHome, isActive: isDashboard},
        {link: 'events', label: t`Events`, icon: IconCalendar},
        {
            link: 'reports',
            label: t`Reports`,
            icon: IconChartBar,
            isActive: (isActive) => isActive || location.pathname.includes('/report/')
        },
    ];

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

    const handleStatusToggle = () => {
        // Check if user email is verified
        if (!isUserEmailVerfied) {
            openEmailVerificationModal();
            return;
        }

        const message = organizer?.status === 'LIVE'
            ? t`Are you sure you want to make this organizer draft? This will make the organizer page invisible to the public`
            : t`Are you sure you want to make this organizer public? This will make the organizer page visible to the public`;

        confirmationDialog(message, () => {
            statusToggleMutation.mutate({
                organizerId,
                status: organizer?.status === 'LIVE' ? 'DRAFT' : 'LIVE'
            }, {
                onSuccess: () => {
                    showSuccess(t`Organizer status updated`);
                },
                onError: (error: any) => {
                    showError(error?.response?.data?.message || t`Organizer status update failed. Please try again later`);
                }
            });
        });
    };

    const bottomItems: NavItem[] = organizer ? [
        {link: `/events/${organizerId}/${organizer.slug || ''}`, label: t`Organizer page`, icon: IconExternalLink},
        {label: t`Share the page`, icon: IconShare, onClick: openShareModal},
        {
            label: organizer.status === 'LIVE' ? t`Page online` : t`Publish the page`,
            icon: organizer.status === 'LIVE' ? IconEye : IconEyeOff,
            onClick: handleStatusToggle,
        },
        {link: 'organizer-homepage-designer', label: t`Customize the page`, icon: IconPaint},
        {link: 'webhooks', label: t`Webhooks`, icon: IconWebhook},
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
                mobileTabs={navItems}
                headerActions={(
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
            {organizer && shareModalOpen && (
                <ShareModal
                    url={organizerHomepageUrl(organizer)}
                    title={organizer.name}
                    modalTitle={t`Share Organizer Page`}
                    opened={shareModalOpen}
                    onClose={closeShareModal}
                />
            )}

            <Modal
                opened={emailVerificationModalOpen}
                onClose={closeEmailVerificationModal}
                title={t`Email Verification Required`}
                size="md"
                centered
            >
                <Stack gap="md">
                    <Text size="sm" c="dimmed">
                        {t`You must verify your email address before you can update the organizer status.`}
                    </Text>

                    {!emailConfirmationResent ? (
                        <Button
                            variant="light"
                            onClick={() => {
                                handleEmailConfirmationResend();
                            }}
                            loading={resendEmailConfirmationMutation.isPending}
                        >
                            {t`Resend Confirmation Email`}
                        </Button>
                    ) : (
                        <Text size="sm">
                            {t`Confirmation email sent! Please check your inbox.`}
                        </Text>
                    )}
                </Stack>
            </Modal>
        </>
    );
};

export default OrganizerLayout;
