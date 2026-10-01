import {Alert, Button, Group, List, Stack, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t, Trans} from "@lingui/macro";
import {IconInfoCircle} from "@tabler/icons-react";
import {GenericModalProps, IdParam} from "../../../types";
import {Modal} from "../../common/Modal";
import {AdminOrganizer} from "../../../api/admin.client";
import {useUpdateAdminOrganizerCustomDomain} from "../../../mutations/useUpdateAdminOrganizerCustomDomain";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler";
import {showSuccess} from "../../../utilites/notifications";

interface EditOrganizerCustomDomainModalProps extends GenericModalProps {
    accountId: IdParam;
    organizer: AdminOrganizer;
}

export const EditOrganizerCustomDomainModal = ({onClose, accountId, organizer}: EditOrganizerCustomDomainModalProps) => {
    const mutation = useUpdateAdminOrganizerCustomDomain(accountId);
    const formErrorHandler = useFormErrorResponseHandler();

    const form = useForm({
        initialValues: {
            custom_domain: organizer.custom_domain || '',
        },
    });

    const save = (customDomain: string | null) => {
        mutation.mutate(
            {organizerId: organizer.id, customDomain},
            {
                onSuccess: () => {
                    showSuccess(customDomain ? t`Custom domain saved` : t`Custom domain removed`);
                    onClose();
                },
                onError: (error: any) => formErrorHandler(form, error),
            }
        );
    };

    return (
        <Modal heading={t`Custom domain for ${organizer.name}`} onClose={onClose} opened>
            <form onSubmit={form.onSubmit((values) => save(values.custom_domain.trim() || null))}>
                <Stack gap="md">
                    <TextInput
                        label={t`Domain name`}
                        description={t`The organizer's homepage and events will be served on this domain. The www. version is handled automatically.`}
                        placeholder="innocent976.yt"
                        {...form.getInputProps('custom_domain')}
                    />

                    <Alert variant="light" icon={<IconInfoCircle size={16}/>} title={t`Before it goes live`}>
                        <List size="sm" spacing={4}>
                            <List.Item>
                                <Trans>At the domain registrar, point the A records of the domain and its www. subdomain to the platform server's IP address.</Trans>
                            </List.Item>
                            <List.Item>
                                <Trans>Then enable HTTPS for the domain on the server with the add-custom-domain.sh script.</Trans>
                            </List.Item>
                        </List>
                    </Alert>

                    <Group justify="space-between">
                        {organizer.custom_domain ? (
                            <Button
                                variant="subtle"
                                color="red"
                                onClick={() => save(null)}
                                loading={mutation.isPending}
                            >
                                {t`Remove domain`}
                            </Button>
                        ) : <div/>}
                        <Button type="submit" loading={mutation.isPending}>
                            {t`Save`}
                        </Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
};
