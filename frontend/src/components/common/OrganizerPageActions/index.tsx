import {useState} from "react";
import {t} from "@lingui/macro";
import {Button, Group, Modal, Stack, Text} from "@mantine/core";
import {useDisclosure} from "@mantine/hooks";
import {IconEye, IconEyeOff, IconShare} from "@tabler/icons-react";
import {Organizer} from "../../../types.ts";
import {ShareModal} from "../../modals/ShareModal";
import {organizerHomepageUrl} from "../../../utilites/urlHelper";
import {useUpdateOrganizerStatus} from "../../../mutations/useUpdateOrganizerStatus.ts";
import {useResendEmailConfirmation} from "../../../mutations/useResendEmailConfirmation.ts";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";

export const OrganizerPageActions = ({organizer}: { organizer: Organizer }) => {
    const [shareModalOpen, {open: openShareModal, close: closeShareModal}] = useDisclosure(false);
    const [emailModalOpen, {open: openEmailModal, close: closeEmailModal}] = useDisclosure(false);
    const [emailConfirmationResent, setEmailConfirmationResent] = useState(false);
    const {data: me} = useGetMe();
    const statusMutation = useUpdateOrganizerStatus();
    const resendMutation = useResendEmailConfirmation();
    const isLive = organizer.status === 'LIVE';

    const handleStatusToggle = () => {
        if (!me?.is_email_verified) {
            openEmailModal();
            return;
        }

        const message = isLive
            ? t`Are you sure you want to make this organizer draft? This will make the organizer page invisible to the public`
            : t`Are you sure you want to make this organizer public? This will make the organizer page visible to the public`;

        confirmationDialog(message, () => {
            statusMutation.mutate({
                organizerId: organizer.id,
                status: isLive ? 'DRAFT' : 'LIVE',
            }, {
                onSuccess: () => showSuccess(t`Organizer status updated`),
                onError: (error: any) => showError(error?.response?.data?.message || t`Organizer status update failed. Please try again later`),
            });
        });
    };

    const handleEmailConfirmationResend = () => {
        resendMutation.mutate({userId: me?.id}, {
            onSuccess: () => {
                showSuccess(t`Email confirmation resent successfully`);
                setEmailConfirmationResent(true);
            },
            onError: () => showError(t`Something went wrong. Please try again.`),
        });
    };

    return (
        <>
            <Group gap="xs" grow>
                <Button
                    variant={isLive ? 'light' : 'filled'}
                    color={isLive ? 'green' : undefined}
                    leftSection={isLive ? <IconEye size={16}/> : <IconEyeOff size={16}/>}
                    loading={statusMutation.isPending}
                    onClick={handleStatusToggle}
                >
                    {isLive ? t`Page online` : t`Publish the page`}
                </Button>
                <Button variant="default" leftSection={<IconShare size={16}/>} onClick={openShareModal}>
                    {t({message: 'Share', context: 'organizer page'})}
                </Button>
            </Group>

            {shareModalOpen && (
                <ShareModal
                    url={organizerHomepageUrl(organizer)}
                    title={organizer.name}
                    modalTitle={t`Share Organizer Page`}
                    opened={shareModalOpen}
                    onClose={closeShareModal}
                />
            )}

            <Modal opened={emailModalOpen} onClose={closeEmailModal} title={t`Email Verification Required`} size="md" centered>
                <Stack gap="md">
                    <Text size="sm" c="dimmed">
                        {t`You must verify your email address before you can update the organizer status.`}
                    </Text>
                    {!emailConfirmationResent ? (
                        <Button variant="light" onClick={handleEmailConfirmationResend} loading={resendMutation.isPending}>
                            {t`Resend Confirmation Email`}
                        </Button>
                    ) : (
                        <Text size="sm">{t`Confirmation email sent! Please check your inbox.`}</Text>
                    )}
                </Stack>
            </Modal>
        </>
    );
};
