import {useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Alert, Badge, Button, Group, Skeleton, Text} from "@mantine/core";
import {IconCalendarPlus, IconPencil, IconUsers, IconX} from "@tabler/icons-react";
import {IdParam, SmsCampaign} from "../../../../types.ts";
import {useGetSmsReminders} from "../../../../queries/useGetSmsReminders.ts";
import {useCancelSmsReminder} from "../../../../mutations/useCancelSmsReminder.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {prettyDate} from "../../../../utilites/dates.ts";
import {CampaignRecipientsDrawer} from "./CampaignRecipientsDrawer.tsx";
import {ReminderFormModal} from "./ReminderFormModal.tsx";
import {reminderStatusColor, reminderStatusLabel} from "./labels.ts";
import classes from "./Sms.module.scss";

interface ReminderListProps {
    organizerId: IdParam;
    canSend: boolean;
    defaultCurrency: string;
    defaultTimezone: string;
}

export const ReminderList = ({organizerId, canSend, defaultCurrency, defaultTimezone}: ReminderListProps) => {
    const remindersQuery = useGetSmsReminders(organizerId);
    const cancelMutation = useCancelSmsReminder();
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<SmsCampaign | null>(null);
    const [selected, setSelected] = useState<SmsCampaign | null>(null);
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const reminders = remindersQuery.data ?? [];

    const cancel = (reminder: SmsCampaign) => {
        cancelMutation.mutate({organizerId, reminderId: reminder.id}, {
            onSuccess: () => showSuccess(t`Reminder cancelled`),
            onError: (error: any) => showError(error?.response?.data?.message || t`The reminder could not be cancelled`),
            onSettled: () => setConfirmingId(null),
        });
    };

    return (
        <div className={classes.campaigns}>
            <div className={classes.campaignHeader}>
                <Text size="sm" c="dimmed" className={classes.reminderIntro}>
                    {t`Send an SMS to the attendees of an event at the date and time of your choice. Unsubscribed numbers are left out and each number receives the reminder only once.`}
                </Text>
                <Button
                    leftSection={<IconCalendarPlus size={16}/>}
                    onClick={() => setFormOpen(true)}
                    disabled={!canSend}
                >
                    {t`Schedule a reminder`}
                </Button>
            </div>

            {remindersQuery.isLoading && <Skeleton height={160} radius="md"/>}

            {!remindersQuery.isLoading && reminders.length === 0 && (
                <Alert variant="light" color="gray">
                    {t`No reminder scheduled yet.`}
                </Alert>
            )}

            {reminders.map(reminder => {
                const timezone = reminder.event_timezone || defaultTimezone;
                const sent = reminder.sent_count + reminder.delivered_count;
                const kpis = [
                    {label: t`Recipients`, value: formatNumber(reminder.recipients_count)},
                    {label: t`Sent`, value: formatNumber(sent)},
                    {label: t`Delivered`, value: formatNumber(reminder.delivered_count)},
                    {label: t`Failed`, value: formatNumber(reminder.failed_count)},
                ];

                return (
                    <section key={reminder.id} className={classes.campaign}>
                        <div className={classes.campaignHeader}>
                            <div>
                                <Group gap="xs" mb={2}>
                                    <h2 className={classes.campaignName}>{reminder.name}</h2>
                                    <Badge variant="light" color={reminderStatusColor(reminder.status)}>
                                        {reminderStatusLabel(reminder.status)}
                                    </Badge>
                                    {reminder.auto_key && !reminder.auto_edited && (
                                        <Badge variant="outline" color="gray">{t`Automatic`}</Badge>
                                    )}
                                </Group>
                                <Text size="sm" c="dimmed">
                                    {reminder.event_title && <Trans>For {reminder.event_title}</Trans>}
                                    {reminder.event_title && reminder.scheduled_at && ', '}
                                    {reminder.scheduled_at && (
                                        <Trans>sending on {prettyDate(reminder.scheduled_at, timezone)}</Trans>
                                    )}
                                </Text>
                            </div>
                            <Group gap="xs">
                                <Button variant="light" leftSection={<IconUsers size={16}/>} onClick={() => setSelected(reminder)}>
                                    {t`See the recipients`}
                                </Button>
                                {reminder.status === 'SCHEDULED' && confirmingId !== reminder.id && (
                                    <Button variant="light" leftSection={<IconPencil size={16}/>} onClick={() => setEditing(reminder)}>
                                        {t`Edit`}
                                    </Button>
                                )}
                                {reminder.status === 'SCHEDULED' && confirmingId !== reminder.id && (
                                    <Button variant="subtle" color="red" leftSection={<IconX size={16}/>} onClick={() => setConfirmingId(reminder.id)}>
                                        {t`Cancel the reminder`}
                                    </Button>
                                )}
                                {reminder.status === 'SCHEDULED' && confirmingId === reminder.id && (
                                    <>
                                        <Button color="red" loading={cancelMutation.isPending} onClick={() => cancel(reminder)}>
                                            {t`Confirm the cancellation`}
                                        </Button>
                                        <Button variant="default" onClick={() => setConfirmingId(null)}>
                                            {t`Keep it`}
                                        </Button>
                                    </>
                                )}
                            </Group>
                        </div>

                        {reminder.message && <div className={classes.reminderSample}>{reminder.message}</div>}

                        <div className={classes.campaignKpis}>
                            {kpis.map(kpi => (
                                <div key={kpi.label} className={classes.kpi}>
                                    <div className={classes.kpiValue}>{kpi.value}</div>
                                    <div className={classes.kpiLabel}>{kpi.label}</div>
                                </div>
                            ))}
                        </div>

                        {reminder.failure_reason && (
                            <Text size="xs" c="red" mt="xs">{reminder.failure_reason}</Text>
                        )}
                    </section>
                );
            })}

            {formOpen && <ReminderFormModal organizerId={organizerId} onClose={() => setFormOpen(false)}/>}

            {editing && (
                <ReminderFormModal
                    key={editing.id}
                    organizerId={organizerId}
                    reminder={editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <CampaignRecipientsDrawer
                organizerId={organizerId}
                campaign={selected}
                currency={selected?.currency || defaultCurrency}
                onClose={() => setSelected(null)}
            />
        </div>
    );
};
