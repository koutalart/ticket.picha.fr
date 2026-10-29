import {useEffect} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {Alert, Button, Group, Radio, Stack, Switch, Text, TextInput} from "@mantine/core";
import {IconMessageChatbot} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useGetOrganizer} from "../../../../../../queries/useGetOrganizer.ts";
import {ImageUploadDropzone} from "../../../../../common/ImageUploadDropzone";
import {LiveChatStyle} from "../../../../../../types.ts";

const MAX_RELAY_LABEL_LENGTH = 80;

/**
 * « Assistance en direct »: the Contact buttons of the public pages open a chat answered by the SMS assistant.
 */
export const LiveChatSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateSmsSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const settings = settingsQuery.data;
    const organizerQuery = useGetOrganizer(organizerId);
    const chatAvatar = organizerQuery.data?.images?.find((image) => image.type === 'ORGANIZER_CHAT_AVATAR');
    const isAvailable = !!settings?.assistant_available;

    const form = useForm({
        initialValues: {
            live_chat_enabled: true,
            live_chat_relay_label: '',
            live_chat_style: 'CLASSIC' as LiveChatStyle,
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                live_chat_enabled: !!settings.live_chat_enabled,
                live_chat_relay_label: settings.live_chat_relay_label || '',
                live_chat_style: settings.live_chat_style || 'CLASSIC',
            });
        }
    }, [settingsQuery.isFetched]);

    const save = (values: typeof form.values) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {
                live_chat_enabled: values.live_chat_enabled,
                live_chat_relay_label: values.live_chat_relay_label.trim() || null,
                live_chat_style: values.live_chat_style,
            },
        }, {
            onSuccess: ({data}) => {
                form.setValues({
                    live_chat_enabled: !!data.live_chat_enabled,
                    live_chat_relay_label: data.live_chat_relay_label || '',
                    live_chat_style: data.live_chat_style || 'CLASSIC',
                });
                showSuccess(t`Live assistance settings saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const relayLabel = form.values.live_chat_relay_label.trim() || t`the organizer`;

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Live assistance`}
                description={t`The "Contact the organizer" buttons of your event and organizer pages open a chat that answers visitors right away, with the same data and instructions as the SMS assistant. On by default.`}
            />

            <Alert color="blue" variant="light" mb="md" icon={<IconMessageChatbot size={16}/>}>
                <Text size="sm">
                    {t`Sensitive questions or questions it cannot answer get a holding reply that promises nothing. The visitor is then asked for a phone number (you reply by SMS, the conversation appears "To handle") or an email. Chats without contact details are listed in SMS, Web chats, and deleted after 90 days.`}
                </Text>
            </Alert>

            <form onSubmit={form.onSubmit(save)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending || !isAvailable}>
                    <Switch
                        {...form.getInputProps('live_chat_enabled', {type: 'checkbox'})}
                        label={t`Open a live chat instead of the contact form`}
                        mb="md"
                    />
                    <TextInput
                        {...form.getInputProps('live_chat_relay_label')}
                        label={t`Who can take over`}
                        description={t`Shown under the chat title. For example: a member of the association.`}
                        placeholder={t`the organizer`}
                        maxLength={MAX_RELAY_LABEL_LENGTH}
                        mb={4}
                    />
                    <Text size="xs" c="dimmed" mb="md">
                        {t`Preview: Immediate answer, ${relayLabel} can take over`}
                    </Text>
                    <Radio.Group
                        {...form.getInputProps('live_chat_style')}
                        label={t`Chat style`}
                        mb="md"
                    >
                        <Stack gap="xs" mt={6}>
                            <Radio
                                value="CLASSIC"
                                label={t`Classic`}
                                description={t`Opened from the Contact buttons of your pages, in a side panel.`}
                            />
                            <Radio
                                value="MESSAGING"
                                label={t`Messaging`}
                                description={t`A round button with the assistant's photo stays at the bottom right of your event and organizer pages and opens a full screen conversation, like a messaging app.`}
                            />
                        </Stack>
                    </Radio.Group>
                    <Group>
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                    </Group>
                </fieldset>
            </form>

            <Text fw={600} size="sm" mt="lg" mb={4}>{t`Assistant photo`}</Text>
            <ImageUploadDropzone
                key={chatAvatar?.id ?? 'none'}
                helpText={t`Round photo shown on the chat button and in the conversation, in the Messaging style. Without a photo, her initial is shown on a coloured circle. A square image of at least 100 by 100 pixels works best.`}
                imageType="ORGANIZER_CHAT_AVATAR"
                entityId={organizerId}
                onUploadSuccess={() => organizerQuery.refetch()}
                onDeleteSuccess={() => organizerQuery.refetch()}
                existingImageData={{
                    url: chatAvatar?.url,
                    id: chatAvatar?.id,
                }}
                displayMode="compact"
            />
        </Card>
    );
};
