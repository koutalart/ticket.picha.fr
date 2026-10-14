import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {Button, Group, Modal, Text, TextInput} from "@mantine/core";
import {QrCampaign} from "../../../../types.ts";
import {useUpsertQrCampaign} from "../../../../mutations/useUpsertQrCampaign.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";

interface QrCampaignModalProps {
    campaign?: QrCampaign;
    onClose: () => void;
    onSaved?: (campaign: QrCampaign) => void;
}

export const QrCampaignModal = ({campaign, onClose, onSaved}: QrCampaignModalProps) => {
    const {organizerId} = useParams();
    const mutation = useUpsertQrCampaign();
    const formErrorHandle = useFormErrorResponseHandler();
    const form = useForm({
        initialValues: {name: campaign?.name || ''},
        validate: {name: (value) => value.trim() ? null : t`Please give the campaign a name`},
    });

    const submit = ({name}: { name: string }) => {
        mutation.mutate({organizerId: organizerId!, campaignId: campaign?.id, name: name.trim()}, {
            onSuccess: ({data}) => {
                showSuccess(campaign ? t`Campaign renamed` : t`Campaign created`);
                onSaved?.(data);
                onClose();
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    return (
        <Modal opened onClose={onClose} title={campaign ? t`Rename the campaign` : t`New campaign`} size="sm" centered>
            <form onSubmit={form.onSubmit(submit)}>
                {!campaign && (
                    <Text size="sm" c="dimmed" mb="md">
                        {t`Group the QR codes of one operation (poster, flyer, story, screen...) to follow its total results.`}
                    </Text>
                )}
                <TextInput {...form.getInputProps('name')} label={t`Campaign name`} placeholder={t`Open day 2026`}
                           maxLength={80} data-autofocus required/>
                <Group justify="flex-end" mt="lg">
                    <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                    <Button type="submit" loading={mutation.isPending}>{campaign ? t`Save` : t`Create`}</Button>
                </Group>
            </form>
        </Modal>
    );
};
