import {useEffect} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {t} from "@lingui/macro";
import {Button, Group, Switch, Text, Textarea} from "@mantine/core";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {useUpdateSmsSettings} from "../../../../../../mutations/useUpdateSmsSettings.ts";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";

const MAX_PROMPT_LENGTH = 2000;
const firstNamePlaceholder = '{prenom}';
const eventNamePlaceholder = '{evenement}';

/**
 * Welcome SMS sent when an attendee's ticket is scanned at the door for the first time,
 * with the organizer's own tone and templates when they are filled in.
 */
export const CheckInWelcomeSmsSettings = () => {
    const {organizerId} = useParams();
    const settingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateSmsSettings();
    const formErrorHandle = useFormErrorResponseHandler();
    const settings = settingsQuery.data;
    const enabled = !!settings?.checkin_welcome_sms_enabled;

    const form = useForm({
        initialValues: {
            checkin_welcome_sms_prompt: '',
            checkin_welcome_sms_templates: '',
        },
    });

    useEffect(() => {
        if (settingsQuery.isFetched && settings) {
            form.setValues({
                checkin_welcome_sms_prompt: settings.checkin_welcome_sms_prompt || '',
                checkin_welcome_sms_templates: settings.checkin_welcome_sms_templates || '',
            });
        }
    }, [settingsQuery.isFetched]);

    const toggle = (value: boolean) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {checkin_welcome_sms_enabled: value},
        }, {
            onSuccess: () => showSuccess(value ? t`Welcome SMS turned on` : t`Welcome SMS turned off`),
            onError: () => showError(t`Unable to save the welcome SMS setting`),
        });
    };

    const saveTone = (values: typeof form.values) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            settings: {
                checkin_welcome_sms_prompt: values.checkin_welcome_sms_prompt.trim() || null,
                checkin_welcome_sms_templates: values.checkin_welcome_sms_templates.trim() || null,
            },
        }, {
            onSuccess: ({data}) => {
                form.setValues({
                    checkin_welcome_sms_prompt: data.checkin_welcome_sms_prompt || '',
                    checkin_welcome_sms_templates: data.checkin_welcome_sms_templates || '',
                });
                showSuccess(t`Welcome SMS tone saved`);
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Welcome SMS at the door`}
                description={t`When a ticket is scanned for the first time, the attendee receives a short, friendly SMS that welcomes them by first name, different every time.`}
            />
            <Switch
                checked={enabled}
                disabled={settingsQuery.isLoading || updateMutation.isPending}
                onChange={(event) => toggle(event.currentTarget.checked)}
                label={t`Send a welcome SMS when a ticket is scanned`}
                mb="xs"
            />
            <Text size="xs" c="dimmed" mb="md">
                {t`Sent to the attendee's phone, or to the order's phone. One SMS per phone number and per event, so a group with one buyer receives a single SMS. Paper tickets without a phone receive nothing.`}
            </Text>

            <form onSubmit={form.onSubmit(saveTone)}>
                <fieldset disabled={settingsQuery.isLoading || updateMutation.isPending}>
                    <Textarea
                        {...form.getInputProps('checkin_welcome_sms_prompt')}
                        label={t`Your tone for the welcome SMS`}
                        description={t`Optional. Added to the instructions used to write each SMS, for example who is speaking, the theme of the evening or the artists. Leave empty for the default tone.`}
                        autosize
                        minRows={3}
                        maxLength={MAX_PROMPT_LENGTH}
                        mb="md"
                    />
                    <Textarea
                        {...form.getInputProps('checkin_welcome_sms_templates')}
                        label={t`Your welcome SMS templates`}
                        description={t`Optional. One template per line, with ${firstNamePlaceholder} for the first name and ${eventNamePlaceholder} for the event name. Used when the SMS cannot be written automatically. 155 characters at most, one emoji at most, no price, link, signature, « équipe », PICHA, scanner, beep or QR code. Leave empty for the default templates.`}
                        autosize
                        minRows={4}
                        mb="md"
                    />
                    <Group>
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                    </Group>
                </fieldset>
            </form>
        </Card>
    );
};
