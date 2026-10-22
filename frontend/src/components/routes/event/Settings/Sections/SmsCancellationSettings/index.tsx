import {useEffect, useRef} from "react";
import {t} from "@lingui/macro";
import {Alert, Anchor, Button, Group, Skeleton, Switch, Text, Textarea} from "@mantine/core";
import {useForm} from "@mantine/form";
import {NavLink, useParams} from "react-router";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {useGetEvent} from "../../../../../../queries/useGetEvent.ts";
import {useGetSmsSettings} from "../../../../../../queries/useGetSmsSettings.ts";
import {getConfig} from "../../../../../../utilites/config.ts";
import {countSmsSegments} from "../../../../organizer/Sms/smsSegments.ts";
import {renderSmsTemplate} from "../SmsConfirmationSettings/renderSmsTemplate.ts";
import classes from "../SmsConfirmationSettings/SmsConfirmationSettings.module.scss";

const MAX_LENGTH = 1000;

const FIRST_NAME = '{prenom}';
const EVENT_TITLE = '{evenement}';
const EVENT_DATE = '{date}';
const EVENT_TIME = '{heure}';
const PERSONAL_LINK = '{lien}';

/**
 * Sent to the attendees, always in French like the other customer messages (same text as the backend default).
 */
export const DEFAULT_SMS_CANCELLATION_MESSAGE = "C'est noté, votre inscription à {evenement} est annulée et votre place est libérée. Au plaisir de vous revoir.";

const SAMPLE_FIRST_NAME = 'Awa';
const SAMPLE_CODE = '3f9c2a7e1b8d4c6f0a5e9b2d7c1f4a8e6b3d0c9f';

interface FormValues {
    sms_cancellation_confirmation_enabled: boolean;
    sms_cancellation_confirmation_message: string;
    sms_cancellation_confirmation_on_organizer_cancel: boolean;
}

export const SmsCancellationSettings = () => {
    const {eventId} = useParams();
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const eventQuery = useGetEvent(eventId);
    const eventSettingsQuery = useGetEventSettings(eventId);
    const organizerId = eventQuery.data?.organizer_id;
    const smsSettingsQuery = useGetSmsSettings(organizerId);
    const updateMutation = useUpdateEventSettings();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<FormValues>({
        initialValues: {
            sms_cancellation_confirmation_enabled: false,
            sms_cancellation_confirmation_message: DEFAULT_SMS_CANCELLATION_MESSAGE,
            sms_cancellation_confirmation_on_organizer_cancel: false,
        },
        validate: {
            sms_cancellation_confirmation_message: (value, values) => values.sms_cancellation_confirmation_enabled && !value.trim()
                ? t`The message cannot be empty`
                : null,
        },
    });

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                sms_cancellation_confirmation_enabled: eventSettingsQuery.data.sms_cancellation_confirmation_enabled ?? false,
                sms_cancellation_confirmation_message: eventSettingsQuery.data.sms_cancellation_confirmation_message || DEFAULT_SMS_CANCELLATION_MESSAGE,
                sms_cancellation_confirmation_on_organizer_cancel: eventSettingsQuery.data.sms_cancellation_confirmation_on_organizer_cancel ?? false,
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const smsSettings = smsSettingsQuery.data;
    const smsConfigured = !!smsSettings?.api_key_configured && !!smsSettings?.from_number;
    const attendanceResponseEnabled = !!eventSettingsQuery.data?.allow_attendee_attendance_response;
    const message = form.values.sms_cancellation_confirmation_message;
    const usesLink = /\{\s*lien\s*\}/i.test(message);

    const event = eventQuery.data;
    const frontendUrl = (getConfig('VITE_FRONTEND_URL') || 'https://ticket.picha.fr').replace(/\/$/, '');
    const preview = renderSmsTemplate(message, {
        firstName: SAMPLE_FIRST_NAME,
        eventTitle: event?.title ?? '',
        startDate: event?.start_date,
        timezone: event?.timezone,
        personalLink: attendanceResponseEnabled ? `${frontendUrl}/venue/${eventId}/a_7Kp2QxR9mWvLz?code=${SAMPLE_CODE}` : null,
    });
    const sample = countSmsSegments(preview);

    const insertVariable = (variable: string) => {
        const textarea = textareaRef.current;
        const start = textarea?.selectionStart ?? message.length;
        const end = textarea?.selectionEnd ?? message.length;
        form.setFieldValue('sms_cancellation_confirmation_message', `${message.slice(0, start)}${variable}${message.slice(end)}`);
        requestAnimationFrame(() => {
            textarea?.focus();
            textarea?.setSelectionRange(start + variable.length, start + variable.length);
        });
    };

    const handleSubmit = (values: FormValues) => {
        updateMutation.mutate({
            eventSettings: {
                sms_cancellation_confirmation_enabled: values.sms_cancellation_confirmation_enabled,
                sms_cancellation_confirmation_message: values.sms_cancellation_confirmation_message.trim() || null,
                sms_cancellation_confirmation_on_organizer_cancel: values.sms_cancellation_confirmation_on_organizer_cancel,
            },
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    };

    const variables = [
        {value: FIRST_NAME, label: t`First name`},
        {value: EVENT_TITLE, label: t`Event title`},
        {value: EVENT_DATE, label: t`Date`},
        {value: EVENT_TIME, label: t`Time`},
        {value: PERSONAL_LINK, label: t`Personal link`},
    ];

    const loading = eventSettingsQuery.isLoading || eventQuery.isLoading || smsSettingsQuery.isLoading;

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Cancellation confirmation SMS`}
                description={t`Each attendee who cancels their registration receives an SMS confirming that their place has been released.`}
            />

            {loading ? <Skeleton height={160} radius="md"/> : (
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <fieldset disabled={updateMutation.isPending}>
                        {!smsConfigured && (
                            <Alert color="yellow" variant="light" mb="md">
                                <Text size="sm">
                                    {t`The SMS messaging of this organizer is not configured yet. Set up the sending line to enable this option.`}
                                </Text>
                                {organizerId && (
                                    <Anchor component={NavLink} to={`/manage/organizer/${organizerId}/sms`} size="sm">
                                        {t`Set up SMS messaging`}
                                    </Anchor>
                                )}
                            </Alert>
                        )}

                        <Switch
                            {...form.getInputProps('sms_cancellation_confirmation_enabled', {type: 'checkbox'})}
                            disabled={!smsConfigured && !form.values.sms_cancellation_confirmation_enabled}
                            label={t`Send an SMS when an attendee cancels their registration`}
                            description={t`One SMS per attendee with a valid number, never twice. Numbers that replied STOP receive nothing.`}
                        />

                        <Switch
                            mt="md"
                            {...form.getInputProps('sms_cancellation_confirmation_on_organizer_cancel', {type: 'checkbox'})}
                            disabled={!form.values.sms_cancellation_confirmation_enabled}
                            label={t`Also send it when I cancel a registration`}
                            description={t`By default, only attendees who cancel by themselves from their attendance page receive it. Turn this on to also send it when you cancel an attendee or an order.`}
                        />

                        {form.values.sms_cancellation_confirmation_enabled && !attendanceResponseEnabled && (
                            <Alert color="yellow" variant="light" mt="md">
                                {t`Attendees can only cancel by themselves when they can confirm or cancel their attendance (Sales tab).`}
                            </Alert>
                        )}

                        <div className={classes.editor}>
                            <Textarea
                                ref={textareaRef}
                                label={t`Message`}
                                autosize
                                minRows={3}
                                maxLength={MAX_LENGTH}
                                {...form.getInputProps('sms_cancellation_confirmation_message')}
                            />
                            <Group gap={6} mt={6} justify="space-between">
                                <Group gap={6}>
                                    <Text size="xs" c="dimmed">{t`Insert:`}</Text>
                                    {variables.map(variable => (
                                        <Button
                                            key={variable.value}
                                            size="compact-xs"
                                            variant="light"
                                            onClick={() => insertVariable(variable.value)}
                                        >
                                            {variable.label}
                                        </Button>
                                    ))}
                                </Group>
                                {message !== DEFAULT_SMS_CANCELLATION_MESSAGE && (
                                    <Button
                                        size="compact-xs"
                                        variant="subtle"
                                        onClick={() => form.setFieldValue('sms_cancellation_confirmation_message', DEFAULT_SMS_CANCELLATION_MESSAGE)}
                                    >
                                        {t`Restore the default text`}
                                    </Button>
                                )}
                            </Group>
                            <Text size="xs" c="dimmed" mt={6}>
                                {variables.map(variable => `${variable.value} ${variable.label}`).join(', ')}
                            </Text>
                            <Text size="xs" c="dimmed">
                                {t`The personal link lets the attendee find their registration again. When there is none for an attendee, the link and its label are left out of the message.`}
                            </Text>
                        </div>

                        {usesLink && !attendanceResponseEnabled && (
                            <Alert color="yellow" variant="light" mt="md">
                                {t`The personal link is only available when attendees can confirm or cancel their attendance (Sales tab). Until then, the link and its label are left out of the message.`}
                            </Alert>
                        )}

                        <div className={classes.preview}>
                            <Text size="sm" fw={600}>{t`Preview`}</Text>
                            <div className={classes.bubble}>{preview}</div>
                            <Text size="xs" c="dimmed">
                                {sample.segments > 1
                                    ? t`${sample.characters} characters, ${sample.segments} SMS per recipient`
                                    : t`${sample.characters} characters, 1 SMS per recipient`}
                            </Text>
                        </div>

                        <Button loading={updateMutation.isPending} type="submit" mt="xl">
                            {t`Save`}
                        </Button>
                    </fieldset>
                </form>
            )}
        </Card>
    );
};
