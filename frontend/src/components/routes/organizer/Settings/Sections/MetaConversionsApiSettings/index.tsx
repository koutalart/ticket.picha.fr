import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {useEffect} from "react";
import {t, Trans} from "@lingui/macro";
import {Alert, Anchor, Badge, Button, Group, List, PasswordInput, Switch, Text, TextInput} from "@mantine/core";
import {IconBrandMeta, IconCircleCheck, IconPlugConnected} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetOrganizerSettings} from "../../../../../../queries/useGetOrganizerSettings.ts";
import {useUpdateOrganizerSettings} from "../../../../../../mutations/useUpdateOrganizerSettings.ts";
import {useTestMetaConversionsApi} from "../../../../../../mutations/useTestMetaConversionsApi.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../../utilites/confirmationDialog.tsx";

export const MetaConversionsApiSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetOrganizerSettings(organizerId);
    const updateMutation = useUpdateOrganizerSettings();
    const testMutation = useTestMetaConversionsApi();
    const formErrorHandle = useFormErrorResponseHandler();
    const settings = settingsQuery.data;
    const isConfigured = !!settings?.meta_conversions_api_configured;
    const hasEnabledFacebookPixel = !!settings?.tracking_pixels?.some(
        pixel => pixel.provider === 'facebook_pixel' && pixel.enabled && pixel.pixel_id
    );

    const form = useForm({
        initialValues: {
            meta_conversions_api_token: '',
            meta_test_event_code: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                meta_conversions_api_token: '',
                meta_test_event_code: settings.meta_test_event_code || '',
            });
        }
    }, [settingsQuery.isFetched]);

    const save = (values: typeof form.values) => {
        const token = values.meta_conversions_api_token.trim();

        updateMutation.mutate({
            organizerId: organizerId!,
            organizerSettings: {
                ...(token ? {meta_conversions_api_token: token} : {}),
                meta_test_event_code: values.meta_test_event_code.trim() || null,
            },
        }, {
            onSuccess: () => {
                form.setFieldValue('meta_conversions_api_token', '');
                showSuccess(t`Conversions API settings saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const removeToken = () => {
        confirmationDialog(t`Remove the access token? Purchases will no longer be sent to Meta from the server.`, () => {
            updateMutation.mutate({
                organizerId: organizerId!,
                organizerSettings: {meta_conversions_api_token: null},
            }, {
                onSuccess: () => showSuccess(t`Access token removed`),
                onError: () => showError(t`Unable to remove the access token`),
            });
        });
    };

    const saveSendWithoutConsent = (enabled: boolean) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            organizerSettings: {meta_capi_send_without_consent: enabled},
        }, {
            onSuccess: () => showSuccess(t`Conversions API settings saved`),
            onError: () => showError(t`Unable to save the setting`),
        });
    };

    const toggleSendWithoutConsent = (enabled: boolean) => {
        if (!enabled) {
            saveSendWithoutConsent(false);
            return;
        }

        confirmationDialog(
            t`Send events to Meta even for visitors who refused cookies? You are responsible for this choice: check with your legal advisor that it is allowed.`,
            () => saveSendWithoutConsent(true),
        );
    };

    const testConnection = () => {
        testMutation.mutate({organizerId: organizerId!}, {
            onSuccess: ({data}) => {
                const pixel = data.pixel_name || data.pixel_id;
                showSuccess(data.test_event_sent
                    ? t`Connected to pixel ${pixel}. A test event was sent: check the Test events tab in Meta Events Manager.`
                    : t`Connected to pixel ${pixel}.`);
            },
            onError: (error: any) => showError(error?.response?.data?.message || t`Unable to reach Meta. Please try again.`),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Meta Conversions API`}
                description={t`Send ticket purchases to Meta directly from our servers. This is more reliable than the pixel alone (ad blockers, iOS restrictions) and improves your ad campaign measurement.`}
            />

            <Group gap="xs" mb="md">
                <IconBrandMeta size={18}/>
                {isConfigured
                    ? <Badge color="green" variant="light" leftSection={<IconCircleCheck size={12}/>}>{t`Configured`}</Badge>
                    : <Badge color="gray" variant="light">{t`Not configured`}</Badge>}
            </Group>

            {!hasEnabledFacebookPixel && (
                <Alert color="yellow" variant="light" mb="md">
                    {t`Add and enable your Facebook Pixel in the Tracking & Analytics section first: the Conversions API sends events to that pixel.`}
                </Alert>
            )}

            <Alert color="blue" variant="light" mb="md">
                <Text size="sm" mb={6}>{t`How it works`}</Text>
                <List size="sm" spacing={4}>
                    <List.Item>{t`Page views, checkout starts and purchases are only sent if the visitor accepted cookies on your pages, unless the option below is turned on.`}</List.Item>
                    <List.Item>{t`Email and names are hashed (SHA-256) before leaving our servers.`}</List.Item>
                    <List.Item>{t`Each purchase carries the same identifier as the pixel event, so Meta counts it only once.`}</List.Item>
                </List>
            </Alert>

            <form onSubmit={form.onSubmit(save)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending}>
                    <PasswordInput
                        {...form.getInputProps('meta_conversions_api_token')}
                        label={t`Access token`}
                        placeholder={isConfigured ? t`A token is saved. Paste a new one to replace it.` : 'EAAB...'}
                        description={
                            <Trans>
                                In Meta Events Manager: your pixel, then Settings, then Conversions API, then
                                Generate access token. <Anchor href="https://www.facebook.com/events_manager2" target="_blank" size="xs">Open Events Manager</Anchor>
                            </Trans>
                        }
                        autoComplete="off"
                        mb="md"
                    />
                    <TextInput
                        {...form.getInputProps('meta_test_event_code')}
                        label={t`Test event code (optional)`}
                        description={t`While it is set, events only appear in the Test events tab and are not used by your campaigns. Remove it once your tests are done.`}
                        placeholder="TEST12345"
                        mb="md"
                    />
                    <Switch
                        checked={!!settings?.meta_capi_send_without_consent}
                        onChange={(event) => toggleSendWithoutConsent(event.currentTarget.checked)}
                        label={t`Send server events without cookie consent`}
                        description={t`Off by default: server events follow each visitor's cookie choice. Advertising tracking, even from the server, generally requires consent (CNIL). Only turn this on after legal advice.`}
                        color="red"
                        mb="md"
                    />
                    <Group>
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                        <Button
                            variant="light"
                            leftSection={<IconPlugConnected size={16}/>}
                            onClick={testConnection}
                            loading={testMutation.isPending}
                            disabled={!isConfigured || !hasEnabledFacebookPixel}
                        >
                            {t`Test the connection`}
                        </Button>
                        {isConfigured && (
                            <Button variant="subtle" color="red" onClick={removeToken}>
                                {t`Remove the token`}
                            </Button>
                        )}
                    </Group>
                </fieldset>
            </form>
        </Card>
    );
};
