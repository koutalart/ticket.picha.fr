import {useEffect} from "react";
import {t} from "@lingui/macro";
import {Alert, Anchor, Button, Checkbox, Group, Skeleton, Switch, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {NavLink, useParams} from "react-router";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useGetEvent} from "../../../../../../queries/useGetEvent.ts";
import {useGetEventDailyReport} from "../../../../../../queries/useGetEventDailyReport.ts";
import {useUpdateEventDailyReport} from "../../../../../../mutations/useUpdateEventDailyReport.ts";
import classes from "./DailyReportSettings.module.scss";

interface FormValues {
    enabled: boolean;
    send_time: string;
    copy_supervisors: boolean;
}

export const DailyReportSettings = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const reportQuery = useGetEventDailyReport(eventId);
    const updateMutation = useUpdateEventDailyReport();
    const formErrorHandle = useFormErrorResponseHandler();
    const report = reportQuery.data;
    const organizerId = eventQuery.data?.organizer_id;

    const form = useForm<FormValues>({
        initialValues: {enabled: false, send_time: '20:00', copy_supervisors: false},
        validate: {
            send_time: (value) => /^([01]\d|2[0-3]):[0-5]\d$/.test(value) ? null : t`The time must be in the HH:MM format`,
        },
    });

    useEffect(() => {
        if (report) {
            form.setValues({enabled: report.enabled, send_time: report.send_time, copy_supervisors: report.copy_supervisors});
        }
    }, [reportQuery.isFetched]);

    const save = (values: FormValues) => {
        updateMutation.mutate({eventId: eventId!, report: values}, {
            onSuccess: () => showSuccess(t`Daily report settings saved`),
            onError: (error) => formErrorHandle(form, error),
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Daily SMS report`}
                description={t`Every day at the chosen time (Mayotte time), the team of the organizer gets by SMS the paid registrations, the registration revenue and the carts in progress.`}
            />

            {reportQuery.isLoading && <Skeleton height={120}/>}

            {report && report.team_contacts_count === 0 && (
                <Alert color="yellow" variant="light" mb="md">
                    <Text size="sm">
                        {t`No team contact yet: add the numbers of the team in the SMS tab before turning the report on.`}{' '}
                        {organizerId && (
                            <Anchor component={NavLink} to={`/manage/organizer/${organizerId}/sms?view=team`} size="sm">
                                {t`Open the team`}
                            </Anchor>
                        )}
                    </Text>
                </Alert>
            )}

            {report && (
                <form onSubmit={form.onSubmit(save)}>
                    <Switch
                        {...form.getInputProps('enabled', {type: 'checkbox'})}
                        label={t`Send the daily report`}
                        mb="md"
                    />
                    <div className={classes.fields}>
                        <TextInput
                            {...form.getInputProps('send_time')}
                            type="time"
                            label={t`Sending time`}
                            w={160}
                        />
                        <Checkbox
                            {...form.getInputProps('copy_supervisors', {type: 'checkbox'})}
                            label={t`Supervision copy`}
                            description={t`Also send it to the platform supervision`}
                        />
                    </div>

                    <Text size="sm" fw={600} mb={4}>{t`Preview with today's figures`}</Text>
                    <div className={classes.preview}>{report.preview}</div>

                    <Group justify="flex-end">
                        <Button type="submit" loading={updateMutation.isPending}>{t`Save`}</Button>
                    </Group>
                </form>
            )}
        </Card>
    );
};
