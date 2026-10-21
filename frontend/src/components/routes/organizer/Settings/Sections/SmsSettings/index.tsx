import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useEffect, useRef} from "react";
import {useQueryClient} from "@tanstack/react-query";
import {t, Trans} from "@lingui/macro";
import {Alert, Anchor, Badge, Button, Divider, Group, List, Loader, PasswordInput, Text, TextInput} from "@mantine/core";
import {IconCircleCheck, IconHistory, IconMessage, IconPlugConnected, IconRefresh} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {CopyButton} from "../../../../../common/CopyButton";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {useTestSmsConnection} from "../../../../../../mutations/useTestSmsConnection.ts";
import {useRegenerateSmsSigningKey} from "../../../../../../mutations/useRegenerateSmsSigningKey.ts";
import {useStartSmsHistoryImport} from "../../../../../../mutations/useStartSmsHistoryImport.ts";
import {GET_SMS_CONVERSATIONS_QUERY_KEY} from "../../../../../../queries/useGetSmsConversations.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";
import {relativeDate} from "../../../../../../utilites/dates.ts";

export const SmsSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateSmsSettings();
    const testMutation = useTestSmsConnection();
    const regenerateMutation = useRegenerateSmsSigningKey();
    const importMutation = useStartSmsHistoryImport();
    const queryClient = useQueryClient();
    const formErrorHandle = useFormErrorResponseHandler();
    const settings = settingsQuery.data;
    const isConfigured = !!settings?.api_key_configured && !!settings?.from_number;
    const historyImport = settings?.history_import;
    const isImporting = historyImport?.status === 'RUNNING';
    const importError = historyImport?.error || t`unknown error`;
    const previousImportStatus = useRef(historyImport?.status);

    useEffect(() => {
        if (previousImportStatus.current === 'RUNNING' && historyImport?.status === 'COMPLETED') {
            queryClient.invalidateQueries({queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, organizerId]});
        }
        previousImportStatus.current = historyImport?.status;
    }, [historyImport?.status]);

    const form = useForm({
        initialValues: {
            api_key: '',
            from_number: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                api_key: '',
                from_number: settings.from_number || '',
            });
        }
    }, [settingsQuery.isFetched]);

    const save = (values: typeof form.values) => {
        const apiKey = values.api_key.trim();

        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {
                ...(apiKey ? {api_key: apiKey} : {}),
                from_number: values.from_number.trim() || null,
            },
        }, {
            onSuccess: ({data}) => {
                form.setValues({api_key: '', from_number: data.from_number || ''});
                showSuccess(t`SMS settings saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const removeApiKey = () => {
        confirmationDialog(t`Remove the httpSMS API key? You will no longer be able to send SMS from PICHA.`, () => {
            updateMutation.mutate({
                organizerId: organizerId!,
                settings: {api_key: null},
            }, {
                onSuccess: () => showSuccess(t`API key removed`),
                onError: () => showError(t`Unable to remove the API key`),
            });
        });
    };

    const regenerateKey = () => {
        confirmationDialog(t`Generate a new signing key? You will need to paste it into your httpSMS webhook, otherwise incoming SMS will be rejected.`, () => {
            regenerateMutation.mutate({organizerId: organizerId!}, {
                onSuccess: () => showSuccess(t`New signing key generated`),
                onError: () => showError(t`Unable to generate a new signing key`),
            });
        });
    };

    const testConnection = () => {
        testMutation.mutate({organizerId: organizerId!}, {
            onSuccess: ({data}) => {
                const phones = data.phone_numbers.join(', ');
                if (data.from_number_registered) {
                    showSuccess(t`Connected to httpSMS. Your sending number is registered.`);
                } else if (data.phone_numbers.length > 0) {
                    showError(t`Connected to httpSMS, but your sending number is not one of your phones: ${phones}`);
                } else {
                    showError(t`Connected to httpSMS, but no phone is registered. Install the httpSMS app on your Android phone.`);
                }
            },
            onError: (error: any) => showError(error?.response?.data?.message || t`Unable to reach httpSMS. Please try again.`),
        });
    };

    const importHistory = () => {
        importMutation.mutate({organizerId: organizerId!}, {
            onSuccess: () => showSuccess(t`Import started. Messages will appear in SMS messages within a few minutes.`),
            onError: (error: any) => showError(error?.response?.data?.message || t`Unable to start the import`),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`SMS (httpSMS)`}
                description={t`Send and receive SMS with your customers from your own Android phone, using the httpSMS app. Replies appear in SMS messages.`}
            />

            <Group gap="xs" mb="md">
                <IconMessage size={18}/>
                {isConfigured
                    ? <Badge color="green" variant="light" leftSection={<IconCircleCheck size={12}/>}>{t`Configured`}</Badge>
                    : <Badge color="gray" variant="light">{t`Not configured`}</Badge>}
                {settings?.last_webhook_received_at && (
                    <Text size="xs" c="dimmed">
                        {t`Last SMS event received ${relativeDate(settings.last_webhook_received_at)}`}
                    </Text>
                )}
            </Group>

            <Alert color="blue" variant="light" mb="md">
                <Text size="sm" mb={6}>{t`How to connect`}</Text>
                <List size="sm" spacing={4} type="ordered">
                    <List.Item>
                        <Trans>
                            Install the httpSMS app on your Android phone and sign in on <Anchor href="https://httpsms.com/settings" target="_blank" size="sm">httpsms.com</Anchor>.
                        </Trans>
                    </List.Item>
                    <List.Item>{t`Copy your API key from the httpSMS settings page and paste it below, with the phone number of the SIM used to send.`}</List.Item>
                    <List.Item>{t`In httpSMS, create a webhook with the URL and signing key below, and tick the message.phone.received, message.phone.sent, message.phone.delivered, message.send.failed and message.send.expired events.`}</List.Item>
                </List>
            </Alert>

            <form onSubmit={form.onSubmit(save)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending}>
                    <PasswordInput
                        {...form.getInputProps('api_key')}
                        label={t`httpSMS API key`}
                        placeholder={settings?.api_key_configured ? t`A key is saved. Paste a new one to replace it.` : 'uk_...'}
                        description={t`Stored encrypted. It is never displayed again.`}
                        autoComplete="off"
                        mb="md"
                    />
                    <TextInput
                        {...form.getInputProps('from_number')}
                        label={t`Sending number`}
                        description={t`The number of the SIM card in the httpSMS phone, for example +262639123456.`}
                        placeholder="+262639123456"
                        mb="md"
                    />
                    <Group mb="lg">
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                        <Button
                            variant="light"
                            leftSection={<IconPlugConnected size={16}/>}
                            onClick={testConnection}
                            loading={testMutation.isPending}
                            disabled={!settings?.api_key_configured}
                        >
                            {t`Test the connection`}
                        </Button>
                        {settings?.api_key_configured && (
                            <Button variant="subtle" color="red" onClick={removeApiKey}>
                                {t`Remove the key`}
                            </Button>
                        )}
                    </Group>
                </fieldset>
            </form>

            {settings && (
                <>
                    <TextInput
                        label={t`Webhook URL`}
                        value={settings.webhook_url}
                        readOnly
                        rightSection={<CopyButton value={settings.webhook_url}/>}
                        mb="md"
                    />
                    <TextInput
                        label={t`Webhook signing key`}
                        description={t`Paste it into the signing key field of your httpSMS webhook. Keep it private.`}
                        value={settings.webhook_signing_key}
                        readOnly
                        rightSection={<CopyButton value={settings.webhook_signing_key}/>}
                        mb="sm"
                    />
                    <Button
                        variant="subtle"
                        size="xs"
                        leftSection={<IconRefresh size={14}/>}
                        onClick={regenerateKey}
                        loading={regenerateMutation.isPending}
                    >
                        {t`Generate a new signing key`}
                    </Button>
                </>
            )}

            <Divider my="lg"/>

            <Text fw={600} size="sm" mb={4}>{t`SMS history`}</Text>
            <Text size="sm" c="dimmed" mb="sm">
                {t`Retrieve the SMS sent and received with your httpSMS number before the webhook was connected, for example a campaign sent by script. Messages already present are not duplicated and are not marked as unread.`}
            </Text>
            <Group gap="sm" align="center">
                <Button
                    variant="light"
                    leftSection={<IconHistory size={16}/>}
                    onClick={importHistory}
                    loading={importMutation.isPending}
                    disabled={!isConfigured || isImporting}
                >
                    {t`Retrieve httpSMS history`}
                </Button>
                {isImporting && (
                    <Group gap={6}>
                        <Loader size="xs"/>
                        <Text size="sm" c="dimmed">
                            {t`Import in progress: ${historyImport?.imported_count ?? 0} messages retrieved`}
                        </Text>
                    </Group>
                )}
                {historyImport?.status === 'COMPLETED' && historyImport.finished_at && (
                    <Text size="sm" c="dimmed">
                        {t`Last import ${relativeDate(historyImport.finished_at)}: ${historyImport.imported_count ?? 0} new messages`}
                    </Text>
                )}
            </Group>
            {historyImport?.status === 'FAILED' && (
                <Alert color="red" variant="light" mt="sm">
                    {t`The import failed: ${importError}. You can start it again, messages already imported are kept.`}
                </Alert>
            )}
        </Card>
    );
};
