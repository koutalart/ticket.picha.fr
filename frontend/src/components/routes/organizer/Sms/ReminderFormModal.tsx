import {useEffect, useMemo, useRef} from "react";
import {t} from "@lingui/macro";
import {Alert, Button, Group, Select, Skeleton, Stack, Text, Textarea, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useDebouncedValue} from "@mantine/hooks";
import {IconCalendarTime} from "@tabler/icons-react";
import {IdParam} from "../../../../types.ts";
import {useGetOrganizerEvents} from "../../../../queries/useGetOrganizerEvents.ts";
import {usePreviewSmsReminder} from "../../../../mutations/usePreviewSmsReminder.ts";
import {useScheduleSmsReminder} from "../../../../mutations/useScheduleSmsReminder.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {Modal} from "../../../common/Modal";
import {DateTimeInput} from "../../../common/DateTimeInput";
import {countSmsSegments} from "./smsSegments.ts";
import classes from "./Sms.module.scss";

const MAX_LENGTH = 1000;

const FIRST_NAME = '{prenom}';
const EVENT_TITLE = '{evenement}';
const EVENT_DATE = '{date}';
const EVENT_TIME = '{heure}';
const PERSONAL_LINK = '{lien}';

interface ReminderFormModalProps {
    organizerId: IdParam;
    onClose: () => void;
}

interface FormValues {
    event_id: string | null;
    send_at: string;
    message: string;
    name: string;
}

export const ReminderFormModal = ({organizerId, onClose}: ReminderFormModalProps) => {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const previewMutation = usePreviewSmsReminder();
    const scheduleMutation = useScheduleSmsReminder();
    const formErrorHandle = useFormErrorResponseHandler();
    const eventsQuery = useGetOrganizerEvents(organizerId, {
        perPage: 100,
        sortBy: 'start_date',
        sortDirection: 'desc',
    });

    const events = eventsQuery.data?.data || [];
    const eventOptions = useMemo(() => events.map(event => ({
        value: String(event.id),
        label: event.title,
    })), [eventsQuery.data]);

    const form = useForm<FormValues>({
        initialValues: {
            event_id: null,
            send_at: '',
            message: t`Hello ${FIRST_NAME}, a reminder: ${EVENT_TITLE} takes place on ${EVENT_DATE} at ${EVENT_TIME}. See you soon!`,
            name: '',
        },
        validate: {
            event_id: (value) => value ? null : t`Choose an event`,
            send_at: (value) => value ? null : t`Choose the date and time of sending`,
            message: (value) => value.trim() ? null : t`The message cannot be empty`,
        },
    });

    const selectedEvent = events.find(event => String(event.id) === form.values.event_id);
    const timezone = previewMutation.data?.data.event_timezone || selectedEvent?.timezone;
    const [debouncedEventId] = useDebouncedValue(form.values.event_id, 300);
    const [debouncedMessage] = useDebouncedValue(form.values.message, 600);

    useEffect(() => {
        if (!debouncedEventId || !debouncedMessage.trim()) {
            previewMutation.reset();
            return;
        }

        previewMutation.mutate({
            organizerId,
            reminder: {event_id: debouncedEventId, message: debouncedMessage},
        });
    }, [debouncedEventId, debouncedMessage]);

    const insertVariable = (variable: string) => {
        const textarea = textareaRef.current;
        const message = form.values.message;
        const start = textarea?.selectionStart ?? message.length;
        const end = textarea?.selectionEnd ?? message.length;
        form.setFieldValue('message', `${message.slice(0, start)}${variable}${message.slice(end)}`);
        requestAnimationFrame(() => {
            textarea?.focus();
            textarea?.setSelectionRange(start + variable.length, start + variable.length);
        });
    };

    const submit = (values: FormValues) => {
        scheduleMutation.mutate({
            organizerId,
            reminder: {
                event_id: values.event_id as string,
                message: values.message,
                send_at: values.send_at.replace('T', ' '),
                name: values.name.trim() || null,
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Reminder scheduled`);
                onClose();
            },
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const preview = previewMutation.data?.data;
    const usesLink = form.values.message.includes(PERSONAL_LINK);
    const sample = preview?.sample_message ? countSmsSegments(preview.sample_message) : null;
    const activeCount = formatNumber(preview?.active_attendees_count ?? 0);
    const withoutPhoneCount = formatNumber(preview?.without_valid_phone_count ?? 0);
    const optedOutCount = formatNumber(preview?.opted_out_count ?? 0);
    const sharedCount = formatNumber(preview?.shared_phone_count ?? 0);

    const variables = [
        {value: FIRST_NAME, label: t`First name`},
        {value: EVENT_TITLE, label: t`Event title`},
        {value: EVENT_DATE, label: t`Date`},
        {value: EVENT_TIME, label: t`Time`},
        {value: PERSONAL_LINK, label: t`Personal link`},
    ];

    return (
        <Modal opened onClose={onClose} heading={t`Schedule an SMS reminder`}>
            <form onSubmit={form.onSubmit(submit)}>
                <Stack gap="md">
                    <Select
                        label={t`Event`}
                        placeholder={t`Choose an event`}
                        data={eventOptions}
                        searchable
                        required
                        nothingFoundMessage={t`No event found`}
                        {...form.getInputProps('event_id')}
                    />

                    <DateTimeInput
                        label={t`Sending date and time`}
                        description={timezone
                            ? t`In the timezone of the event (${timezone})`
                            : t`In the timezone of the event`}
                        required
                        clearable={false}
                        {...form.getInputProps('send_at')}
                    />

                    <div>
                        <Textarea
                            ref={textareaRef}
                            label={t`Message`}
                            autosize
                            minRows={4}
                            maxLength={MAX_LENGTH}
                            required
                            {...form.getInputProps('message')}
                        />
                        <Group gap={6} mt={6}>
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
                    </div>

                    <TextInput
                        label={t`Name of the reminder`}
                        description={t`Optional, only visible to you`}
                        maxLength={120}
                        {...form.getInputProps('name')}
                    />

                    {usesLink && preview && !preview.personal_link_available && (
                        <Alert color="yellow" variant="light">
                            {t`The personal confirmation link is not available yet: it will be left out of the message until it is.`}
                        </Alert>
                    )}

                    {form.values.event_id && previewMutation.isPending && !preview && <Skeleton height={110} radius="md"/>}

                    {preview && (
                        <div className={classes.reminderPreview}>
                            <div className={classes.campaignKpis}>
                                <div className={classes.kpi}>
                                    <div className={classes.kpiValue}>{formatNumber(preview.recipients_count)}</div>
                                    <div className={classes.kpiLabel}>{t`Recipients`}</div>
                                </div>
                                <div className={classes.kpi}>
                                    <div className={classes.kpiValue}>{formatNumber(preview.sms_count)}</div>
                                    <div className={classes.kpiLabel}>{t`SMS to send`}</div>
                                </div>
                            </div>

                            <Text size="xs" c="dimmed" mt="xs">
                                {t`${activeCount} active attendees, ${withoutPhoneCount} without a valid number, ${optedOutCount} unsubscribed, ${sharedCount} sharing a number already reached.`}
                            </Text>

                            {preview.sample_message && (
                                <>
                                    <Text size="sm" fw={600} mt="sm">{t`Example`}</Text>
                                    <div className={classes.reminderSample}>{preview.sample_message}</div>
                                    {sample && (
                                        <Text size="xs" c="dimmed" mt={4}>
                                            {sample.segments > 1
                                                ? t`${sample.characters} characters, ${sample.segments} SMS per recipient`
                                                : t`${sample.characters} characters, 1 SMS per recipient`}
                                        </Text>
                                    )}
                                </>
                            )}
                        </div>
                    )}

                    <Text size="xs" c="dimmed">
                        {t`The recipients are recalculated when the reminder is sent: people who register later receive it too, cancelled attendees and opt-outs do not.`}
                    </Text>

                    <Group justify="flex-end">
                        <Button variant="default" onClick={onClose}>{t`Cancel`}</Button>
                        <Button
                            type="submit"
                            leftSection={<IconCalendarTime size={16}/>}
                            loading={scheduleMutation.isPending}
                        >
                            {t`Schedule`}
                        </Button>
                    </Group>
                </Stack>
            </form>
        </Modal>
    );
};
