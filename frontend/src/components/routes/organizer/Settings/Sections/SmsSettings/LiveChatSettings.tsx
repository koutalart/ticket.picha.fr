import {useEffect} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {Alert, Button, Group, Switch, Text, TextInput} from "@mantine/core";
import {IconMessageChatbot} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";

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
    const isAvailable = !!settings?.assistant_available;

    const form = useForm({
        initialValues: {
            live_chat_enabled: true,
            live_chat_relay_label: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                live_chat_enabled: !!settings.live_chat_enabled,
                live_chat_relay_label: settings.live_chat_relay_label || '',
            });
        }
    }, [settingsQuery.isFetched]);

    const save = (values: typeof form.values) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {
                live_chat_enabled: values.live_chat_enabled,
                live_chat_relay_label: values.live_chat_relay_label.trim() || null,
            },
        }, {
            onSuccess: ({data}) => {
                form.setValues({
                    live_chat_enabled: !!data.live_chat_enabled,
                    live_chat_relay_label: data.live_chat_relay_label || '',
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
                    <Group>
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                    </Group>
                </fieldset>
            </form>
        </Card>
    );
};
