import {useEffect, useState} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {Alert, Badge, Button, Group, Paper, Switch, Text, Textarea, TextInput} from "@mantine/core";
import {IconEye, IconRobot, IconUserExclamation} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {usePreviewSmsAssistant} from "../../../../../../mutations/usePreviewSmsAssistant.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {SmsAssistantPreview} from "../../../../../../types.ts";

const MAX_INSTRUCTIONS_LENGTH = 2000;
const MAX_HOLDING_REPLY_LENGTH = 320;

export const SmsAssistantSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateSmsSettings();
    const previewMutation = usePreviewSmsAssistant();
    const formErrorHandle = useFormErrorResponseHandler();
    const settings = settingsQuery.data;
    const isAvailable = !!settings?.assistant_available;
    const [sampleMessage, setSampleMessage] = useState('');
    const [preview, setPreview] = useState<SmsAssistantPreview | null>(null);

    const form = useForm({
        initialValues: {
            assistant_enabled: true,
            assistant_instructions: '',
            assistant_auto_send: false,
            assistant_holding_reply: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                assistant_enabled: settings.assistant_enabled,
                assistant_instructions: settings.assistant_instructions || '',
                assistant_auto_send: !!settings.assistant_auto_send,
                assistant_holding_reply: settings.assistant_holding_reply || '',
            });
        }
    }, [settingsQuery.isFetched]);

    const save = (values: typeof form.values) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {
                assistant_enabled: values.assistant_enabled,
                assistant_instructions: values.assistant_instructions.trim() || null,
                assistant_auto_send: values.assistant_auto_send,
                assistant_holding_reply: values.assistant_holding_reply.trim() || null,
            },
        }, {
            onSuccess: ({data}) => {
                form.setValues({
                    assistant_enabled: data.assistant_enabled,
                    assistant_instructions: data.assistant_instructions || '',
                    assistant_auto_send: !!data.assistant_auto_send,
                    assistant_holding_reply: data.assistant_holding_reply || '',
                });
                showSuccess(t`Assistant settings saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const showPreview = () => {
        const message = sampleMessage.trim() || t`Hello, how much is the entry and where is the party?`;

        setPreview(null);
        previewMutation.mutate({
            organizerId: organizerId!,
            message,
            instructions: form.values.assistant_instructions.trim() || null,
        }, {
            onSuccess: ({data}) => setPreview(data),
            onError: (error: any) => showError(error?.response?.data?.message || t`The assistant could not suggest a reply. Please try again.`),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS assistant`}
                description={t`When a customer writes to you, the assistant prepares a suggested reply from your events, prices and the customer's orders. Unless you turn on automatic sending, nothing leaves without you: you read the suggestion and click Send.`}
            />

            {settingsQuery.isFetched && !isAvailable && (
                <Alert color="gray" variant="light" mb="md">
                    {t`The SMS assistant is not available on this platform yet.`}
                </Alert>
            )}

            <Alert color="blue" variant="light" mb="md" icon={<IconRobot size={16}/>}>
                <Text size="sm">
                    {t`Refunds, complaints, unusual requests and questions it cannot answer from your data are marked "To handle" with the reason, without a suggestion.`}
                </Text>
            </Alert>

            <form onSubmit={form.onSubmit(save)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending || !isAvailable}>
                    <Switch
                        {...form.getInputProps('assistant_enabled', {type: 'checkbox'})}
                        label={t`Suggest replies to my customers' SMS`}
                        description={t`Turned on by default. You can turn it off at any time.`}
                        mb="md"
                    />
                    <Textarea
                        {...form.getInputProps('assistant_instructions')}
                        label={t`Instructions for the assistant`}
                        description={t`Tone, what to say or never say, useful information. For example: use "tu", stay short and friendly, doors open at 10pm.`}
                        autosize
                        minRows={3}
                        maxRows={10}
                        maxLength={MAX_INSTRUCTIONS_LENGTH}
                        mb="md"
                    />
                    <Switch
                        {...form.getInputProps('assistant_auto_send', {type: 'checkbox'})}
                        label={t`Send the assistant's replies automatically`}
                        description={t`Off by default. A few seconds after a customer SMS, the suggested reply leaves on its own, only for simple questions answered by your instructions and your data.`}
                        disabled={!form.values.assistant_enabled}
                        mb="xs"
                    />
                    {form.values.assistant_auto_send && (
                        <>
                            <Alert color="yellow" variant="light" mb="md">
                                <Text size="sm">
                                    {t`Safeguards: at most one automatic reply per customer SMS, nothing if you or your team already wrote to the contact after it, no reply to a simple "ok" or "thanks", and never after STOP. Complaints, place or invitation requests, press, minors, accessibility, refunds and any doubt stay a suggestion to validate: the customer only gets a holding reply that promises nothing, and the conversation stays "To handle".`}
                                </Text>
                            </Alert>
                            <Textarea
                                {...form.getInputProps('assistant_holding_reply')}
                                label={t`Holding reply`}
                                description={t`Used when the assistant cannot write one in your tone. Add your signature if you want one. Leave empty to use: "Good question, I am checking with the team and will get back to you very soon."`}
                                autosize
                                minRows={2}
                                maxRows={6}
                                maxLength={MAX_HOLDING_REPLY_LENGTH}
                                mb="md"
                            />
                        </>
                    )}
                    <Group mb="lg">
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                    </Group>
                </fieldset>
            </form>

            <Text fw={500} size="sm" mb={4}>{t`Preview`}</Text>
            <Text size="xs" c="dimmed" mb="xs">
                {t`Write a message as if you were a customer to see what the assistant would suggest with the instructions above.`}
            </Text>
            <Group align="flex-end" mb="sm" wrap="nowrap">
                <TextInput
                    value={sampleMessage}
                    onChange={(event) => setSampleMessage(event.currentTarget.value)}
                    placeholder={t`Hello, how much is the entry and where is the party?`}
                    maxLength={640}
                    style={{flex: 1}}
                    disabled={!isAvailable}
                />
                <Button
                    variant="light"
                    leftSection={<IconEye size={16}/>}
                    onClick={showPreview}
                    loading={previewMutation.isPending}
                    disabled={!isAvailable}
                >
                    {t`Preview`}
                </Button>
            </Group>

            {preview && preview.outcome === 'DRAFT' && (
                <Paper withBorder p="sm" radius="md">
                    <Badge color="violet" variant="light" mb={6}>{t`Suggested reply`}</Badge>
                    <Text size="sm" style={{whiteSpace: 'pre-wrap'}}>{preview.reply}</Text>
                </Paper>
            )}
            {preview && preview.outcome === 'NEEDS_HUMAN' && (
                <Alert color="orange" variant="light" icon={<IconUserExclamation size={16}/>} title={t`To handle`}>
                    {preview.reason}
                </Alert>
            )}
        </Card>
    );
};
